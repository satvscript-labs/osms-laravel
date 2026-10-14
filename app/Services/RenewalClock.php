<?php

namespace App\Services;

use App\Mail\RenewalReminderMail;
use App\Models\BillingCharge;
use App\Models\BillingReminder;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * E4 — the daily clock (16_E4_PLAN.md §4).
 *
 * For every paying customer whose period ends within LEAD_DAYS, or who already
 * has an open renewal bill:
 *
 *   1. the renewal bill is RAISED 7 days ahead (D-8, E4-A), or the open one is
 *      re-quoted if what they would pay changed (E4-B — the safety net under the
 *      immediate hooks);
 *   2. the reminder STAGE for today is worked out (E4-C) and, if emails are on
 *      (E4-D) and that stage has not gone out for this cycle, the owner is
 *      emailed — once, guarded by a unique sent-log row written in the same
 *      transaction as the queued email.
 *
 * Every write goes through the engine (audited as `system`). A dry run quotes
 * and reads only: nothing is raised, nothing is sent, nothing is logged.
 */
final class RenewalClock
{
    public const LEAD_DAYS = 7;

    private const TERM_KINDS = ['first_term', 'renewal'];

    public function __construct(private BillingEngine $engine) {}

    /** @return list<array{account: string, status: string, ends: ?string, days: ?int, bill: string, stage: ?string, email: string, note: ?string, error: bool}> */
    public function run(bool $dryRun = false): array
    {
        $tz = config('billing.timezone', 'Asia/Kolkata');
        $today = Carbon::today($tz);
        $horizon = $today->copy()->addDays(self::LEAD_DAYS)->toDateString();
        $emailsOn = PlatformSetting::renewalRemindersOn();

        $withOpenBill = BillingCharge::query()->pending()->whereIn('kind', self::TERM_KINDS)
            ->whereNull('reopened_at')->whereNotNull('open_key')->distinct()->pluck('account_id');

        $subs = Subscription::withoutGlobalScopes()
            ->whereNotNull('account_id')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('status', ['active', 'past_due'])
                    ->whereNotNull('current_period_end')
                    ->whereDate('current_period_end', '<=', $horizon))
                ->orWhereIn('account_id', $withOpenBill))
            ->with(['account.owner', 'account.stores'])
            ->orderBy('current_period_end')
            ->get();

        $lines = [];

        foreach ($subs as $s) {
            try {
                $lines[] = $this->one($s, $today, $dryRun, $emailsOn);
            } catch (\Throwable $e) {
                // One customer's problem never stops the others (and is never silent).
                Log::warning('Renewal clock: could not process a subscription.', ['subscription' => $s->id, 'error' => $e->getMessage()]);
                $lines[] = $this->line($s, null, '—', null, '—', 'Error: ' . $e->getMessage(), error: true);
            }
        }

        return $lines;
    }

    private function one(Subscription $s, Carbon $today, bool $dryRun, bool $emailsOn): array
    {
        $tz = config('billing.timezone', 'Asia/Kolkata');
        $end = $s->current_period_end ? Carbon::parse($s->current_period_end->toDateString(), $tz) : null;
        $days = $end ? (int) $today->diffInDays($end, false) : null;
        $skip = $this->skipReason($s, $end);
        $open = $this->openTerm($s);

        // 1 · The bill.
        $bill = $open;
        $billText = '—';

        if ($open && $s->status !== 'canceled') {
            [$bill, $billText] = $this->keepHonest($s, $open, $dryRun);
        } elseif (! $open && $skip === null && $days !== null && $days <= self::LEAD_DAYS) {
            [$bill, $billText] = $this->raise($s, $dryRun);
        }

        // 2 · The reminder.
        $stage = ($skip === null && $days !== null) ? $this->stage($s, $days) : null;
        $email = '—';

        if ($stage !== null && $bill !== null) {
            $email = $this->remind($s, $bill, $stage, $end, $days, $dryRun, $emailsOn);
        }

        return $this->line($s, $days, $billText, $stage, $email, $skip);
    }

    /**
     * E4-A / E4-F — why this cycle is not billed or chased automatically, or null.
     * A paid renewal's own override (`manual_renewal`) is NOT a reason: since E3
     * every payer carries one until their period ends.
     */
    public function skipReason(Subscription $s, ?Carbon $end): ?string
    {
        $account = $s->account;

        return match (true) {
            ! $account => 'No customer account',
            $s->status === 'trialing' => 'On trial — not billed ahead',
            $s->status === 'canceled' => 'Cancelled',
            (bool) $s->cancel_at_period_end => 'Cancels at the end of the period',
            $this->operatorManaged($s, $end) => 'Decided by you: ' . $s->overrideLabel(),
            $this->voidedByAPerson($s, $end) => 'You voided this renewal — not raised again this cycle',
            $account->billableStoreCount() === 0 => 'No store being billed',
            default => null,
        };
    }

    /** E4-F — a cycle ending under a comp, extension, suspension or cancellation. */
    private function operatorManaged(Subscription $s, ?Carbon $end): bool
    {
        if (! $s->override_kind || $s->override_kind === 'manual_renewal') {
            return false;
        }

        return $s->override_until === null || ! $end
            || $s->override_until->toDateString() >= $end->toDateString();
    }

    /**
     * A-16 — a person voided this cycle's renewal bill ("not owed", "no longer
     * chased"). The clock must not raise it again the next morning: automation never
     * overrules a person (playbook §5.2 rule 2). The next cycle starts afresh.
     */
    private function voidedByAPerson(Subscription $s, ?Carbon $end): bool
    {
        if (! $end) {
            return false;
        }

        return BillingCharge::query()->where('account_id', $s->account_id)->where('status', 'void')
            ->whereIn('kind', self::TERM_KINDS)->whereNotNull('voided_by')
            ->where('voided_at', '>=', $end->copy()->subDays(self::LEAD_DAYS)->startOfDay())
            ->exists();
    }

    /** E4-C — today's stage for a cycle `$days` from its end (negative once ended). */
    public function stage(Subscription $s, int $days): ?string
    {
        return match (true) {
            $days > self::LEAD_DAYS => null,
            $days >= 2 => 'due_7',
            $days === 1 => 'due_1',
            $days === 0 => 'due_0',
            $s->accessState() === 'grace' => 'grace',
            $s->accessState() === 'locked' => 'locked',
            default => null,
        };
    }

    private function openTerm(Subscription $s): ?BillingCharge
    {
        return BillingCharge::query()->where('account_id', $s->account_id)->pending()
            ->whereIn('kind', self::TERM_KINDS)->whereNull('reopened_at')->whereNotNull('open_key')
            ->first();
    }

    /** @return array{0: ?BillingCharge, 1: string} */
    private function keepHonest(Subscription $s, BillingCharge $open, bool $dryRun): array
    {
        if ($open->isBeingPaidOnline()) {
            return [$open, "{$open->number} kept — being paid online"];
        }

        if ($dryRun) {
            $quote = $this->engine->quoteTerm($s, null, max(1, (int) $open->cycles));
            $same = $quote['open_key'] === $open->open_key && abs((float) $quote['amount'] - (float) $open->amount) < 0.005
                && $quote['interval'] === $open->interval;

            return [$open, $same
                ? "{$open->number} ₹" . number_format((float) $open->amount, 2) . ' (open)'
                : "{$open->number} would be re-quoted → ₹" . number_format((float) $quote['amount'], 2)];
        }

        $bill = $this->engine->requoteOpenTerm($s, ['via' => 'system', 'actor' => 'system']);

        return [$bill, $bill && $bill->id !== $open->id
            ? "{$open->number} re-quoted → {$bill->number} ₹" . number_format((float) $bill->amount, 2)
            : "{$open->number} ₹" . number_format((float) $open->amount, 2) . ' (open)'];
    }

    /** @return array{0: ?BillingCharge, 1: string} */
    private function raise(Subscription $s, bool $dryRun): array
    {
        if ($dryRun) {
            $quote = $this->engine->quoteTerm($s);

            return [new BillingCharge(['amount' => $quote['amount']]), 'would raise ₹' . number_format((float) $quote['amount'], 2)];
        }

        $bill = $this->engine->raiseTerm($s, ['via' => 'system', 'actor' => 'system']);

        return [$bill, "raised {$bill->number} ₹" . number_format((float) $bill->amount, 2)];
    }

    /** E4-C / E4-D / E4-E — email the payer once for this stage of this cycle. */
    private function remind(Subscription $s, BillingCharge $bill, string $stage, Carbon $end, int $days, bool $dryRun, bool $emailsOn): string
    {
        $account = $s->account;
        $cycleEnd = $end->toDateString();
        $sent = BillingReminder::query()->where('account_id', $account->id)
            ->whereDate('cycle_end', $cycleEnd)->where('stage', $stage)->exists();

        if ($sent) {
            return 'already sent';
        }

        if (! $emailsOn) {
            return 'emails off';
        }

        $to = collect([$account->owner?->email, $account->billing_email])->filter()->map(fn ($e) => trim($e))->unique()->values()->all();

        if ($to === []) {
            return 'no email on file';
        }

        if ($dryRun) {
            return 'would email ' . implode(', ', $to);
        }

        $mail = new RenewalReminderMail(
            stage: $stage,
            customerName: $account->owner?->name ?: $account->displayName(),
            amount: (float) $bill->amount,
            dueOn: $cycleEnd,
            daysLeft: $days,
            accessUntil: $end->copy()->addDays($s->graceDays())->toDateString(),
            billNumber: $bill->number,
            billedByHand: $account->isSupervised(),
        );

        try {
            // The log row and the queued email commit together: never one without the other.
            DB::transaction(function () use ($account, $s, $bill, $cycleEnd, $stage, $to, $mail) {
                BillingReminder::create([
                    'account_id' => $account->id,
                    'subscription_id' => $s->id,
                    'charge_id' => $bill->exists ? $bill->id : null,
                    'cycle_end' => $cycleEnd,
                    'stage' => $stage,
                    'channel' => 'email',
                    'recipients' => mb_substr(implode(', ', $to), 0, 500),
                    'amount' => $bill->amount,
                    'sent_at' => now(),
                ]);

                Mail::to($to)->queue($mail);
            });
        } catch (UniqueConstraintViolationException) {
            return 'already sent';   // another run got there first
        }

        return 'emailed ' . implode(', ', $to);
    }

    private function line(Subscription $s, ?int $days, string $bill, ?string $stage, string $email, ?string $note, bool $error = false): array
    {
        return [
            'account' => $s->account?->displayName() ?? '—',
            'status' => $s->displayStatus(),
            'ends' => $s->current_period_end?->toDateString(),
            'days' => $days,
            'bill' => $bill,
            'stage' => $stage,
            'email' => $email,
            'note' => $note,
            'error' => $error,
        ];
    }
}

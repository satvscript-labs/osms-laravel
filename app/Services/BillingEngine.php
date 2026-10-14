<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * E2.2 — what is OWED: quote, raise, void, supersede (06 §4).
 *
 * A charge is priced HERE (rule 3) and its amount is locked at raise. Raising one
 * changes nothing about access (rule 2): this class has no reference to the
 * lifecycle — only `Settlement` grants coverage, and only for money received.
 *
 * Every write locks the account's subscription row first (E7), so a raise, a
 * settle and a lifecycle action for one customer are serialised. Lock order is
 * always subscription → charge, everywhere, so two of them can never deadlock.
 */
class BillingEngine
{
    public function __construct(private readonly PriceResolver $prices) {}

    // ---------------------------------------------------------------
    // Quote — what the next term would cost. Reads only.
    // ---------------------------------------------------------------

    /**
     * The next term: from the clock base (the later of the period end and today,
     * so nobody pays for days already covered or days they were locked out), at
     * the pending interval if one is set, for the branches billed from then on.
     *
     * @return array{kind: string, interval: string, cycles: int, quantity: int, unit_price: float,
     *               tier_label: ?string, per_cycle: float, amount: float, period_start: Carbon,
     *               period_end: Carbon, open_key: string, calculation: array}
     */
    public function quoteTerm(Subscription $s, ?string $interval = null, int $cycles = 1): array
    {
        $interval = $interval ?: ($s->pending_interval ?: ($s->interval ?: 'monthly'));

        if (! in_array($interval, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('Interval must be monthly or yearly.');
        }

        if ($cycles < 1 || $cycles > BillingCycle::maxCycles($interval)) {
            throw new InvalidArgumentException('Pay ahead for between 1 and ' . BillingCycle::maxCycles($interval)
                . ($interval === 'yearly' ? ' years.' : ' months.'));
        }

        $quantity = $s->account ? max(1, $s->account->billableStores()->count()) : max(1, (int) $s->quantity);
        $breakdown = $this->prices->breakdown($s, $interval, $quantity);
        $perCycle = $breakdown['effective'];
        $amount = round($perCycle * $cycles, 2);

        $start = $this->clockBase($s);
        $end = BillingCycle::add($start, $interval, $cycles, BillingCycle::anchorOf($s, $start));   // FB-32
        $unit = $interval === 'yearly' ? 'year' : 'month';

        $lines = [
            ['label' => 'Period', 'value' => $start->format('d M Y') . ' → ' . $end->format('d M Y')],
            ['label' => 'Rate', 'value' => '₹ ' . number_format($breakdown['unit_price'], 2) . ' per branch per ' . $unit
                . ($breakdown['tier_label'] ? " ({$breakdown['tier_label']})" : '')
                . ($breakdown['negotiated_price'] !== null ? ' — negotiated' : '')],
            ['label' => 'Branches', 'value' => (string) $quantity],
        ];

        if ($cycles > 1) {
            $lines[] = ['label' => 'Paid ahead', 'value' => "{$cycles} {$unit}s × ₹ " . number_format($perCycle, 2)];
        }

        $lines[] = ['label' => 'Due', 'value' => '₹ ' . number_format($amount, 2)];

        return [
            'kind' => $s->status === 'trialing' ? 'first_term' : 'renewal',
            'interval' => $interval,
            'cycles' => $cycles,
            'quantity' => $quantity,
            'unit_price' => $breakdown['unit_price'],
            'tier_label' => $breakdown['tier_label'],
            'per_cycle' => $perCycle,
            'amount' => $amount,
            'period_start' => $start,
            'period_end' => $end,
            'open_key' => self::termKey($s),
            'calculation' => ['kind' => 'term', 'lines' => $lines],
        ];
    }

    /**
     * E6 — one open TERM charge per account per period being renewed. Keyed on the
     * period END being renewed (stable until the clock moves), not on today: a
     * lapsed customer's term must be the same charge tomorrow, not a second one.
     */
    public static function termKey(Subscription $s): string
    {
        return 'term:' . $s->account_id . ':' . ($s->current_period_end?->toDateString() ?? 'none');
    }

    public static function branchKey(Tenant $branch): string
    {
        return 'branch_join:' . $branch->id;
    }

    // ---------------------------------------------------------------
    // Raise
    // ---------------------------------------------------------------

    /**
     * The next term, as a pending charge. Idempotent on its open_key: the same
     * quote again returns the charge already open; a DIFFERENT quote (price,
     * branches, period or cycles moved) supersedes it.
     */
    public function raiseTerm(Subscription $subscription, array $options = []): BillingCharge
    {
        return $this->withLockedSubscription($subscription, function (Subscription $s) use ($options) {
            $quote = $this->quoteTerm($s, $options['interval'] ?? null, (int) ($options['cycles'] ?? 1));

            return $this->raise($s, $this->termFields($quote), $options);
        });
    }

    /** A term quote as the columns of a charge. */
    private function termFields(array $quote): array
    {
        return [
            'kind' => $quote['kind'],
            'amount' => $quote['amount'],
            'cycles' => $quote['cycles'],
            'interval' => $quote['interval'],
            'quantity' => $quote['quantity'],
            'unit_price' => $quote['unit_price'],
            'tier_label' => $quote['tier_label'],
            'calculation' => $quote['calculation'],
            'period_start' => $quote['period_start'],
            'period_end' => $quote['period_end'],
            'due_on' => $quote['period_start'],
            'open_key' => $quote['open_key'],
        ];
    }

    /**
     * E4-B — keep the open term bill honest: it is replaced by a fresh quote when
     * what they would pay (amount, period, cycles) differs, or when their clock
     * moved under it (an extension, free months) so its dates are stale. raise()
     * keeps the same bill when nothing moved.
     *
     * Left alone: no open term bill, a bill being paid online right now (E3-B; a
     * later run retries), a REOPENED bill (debt for time already given, E2-G).
     * Paying late is not a reason: the clock has not moved (A-7 re-dates it when
     * they pay). Returns the open bill afterwards.
     */
    public function requoteOpenTerm(Subscription $subscription, array $options = []): ?BillingCharge
    {
        if (! $subscription->account_id) {
            return null;
        }

        return $this->withLockedSubscription($subscription, function (Subscription $s) use ($options) {
            $open = BillingCharge::query()->where('account_id', $s->account_id)->pending()
                ->whereIn('kind', ['first_term', 'renewal'])
                ->whereNull('reopened_at')->whereNotNull('open_key')
                ->lockForUpdate()->first();

            if (! $open || $open->isBeingPaidOnline()) {
                return $open;
            }

            $fields = $this->termFields($this->quoteTerm($s, null, max(1, (int) $open->cycles)));

            return $this->raise($s, $fields, $options);
        });
    }

    /**
     * E4-B — re-quote this account's open term bill once the current transaction
     * has committed. Never fails the change that triggered it (a store closure, a
     * price edit): a refusal is logged, and the daily run re-quotes again.
     */
    public static function requoteAfterCommit(?string $accountId, array $options = []): void
    {
        if (! $accountId) {
            return;
        }

        DB::afterCommit(function () use ($accountId, $options) {
            try {
                $sub = Subscription::withoutGlobalScopes()->where('account_id', $accountId)->first();

                if ($sub) {
                    app(self::class)->requoteOpenTerm($sub, $options);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Could not re-quote the open renewal bill; the daily run will retry.',
                    ['account' => $accountId, 'error' => $e->getMessage()]);
            }
        });
    }

    /** E4-B — every account with an open term bill (a list price changed). */
    public static function requoteEveryOpenTermAfterCommit(array $options = []): void
    {
        DB::afterCommit(function () use ($options) {
            BillingCharge::query()->pending()->whereIn('kind', ['first_term', 'renewal'])
                ->whereNull('reopened_at')->distinct()->pluck('account_id')
                ->each(fn ($id) => self::requoteAfterCommit($id, $options));
        });
    }

    /**
     * FB-18 — a branch that joined mid-cycle owes its part period. A PENDING charge
     * with the full working: nothing is recorded as received until it is.
     *
     * @param  array  $proration  Proration::forNewBranch() for this branch
     */
    public function raiseBranchJoin(Subscription $subscription, Tenant $branch, array $proration, array $options = []): BillingCharge
    {
        if (! ($proration['applicable'] ?? false) || ($proration['amount'] ?? 0) <= 0) {
            throw new InvalidArgumentException('There is no part-period charge to raise for this branch.');
        }

        return $this->withLockedSubscription($subscription, fn (Subscription $s) => $this->raise($s, [
            'kind' => 'branch_join',
            'tenant_id' => $branch->id,
            'amount' => $proration['amount'],
            'cycles' => 1,
            'interval' => $proration['interval'],
            'quantity' => $proration['branches'],
            'unit_price' => $proration['unit_price'],
            'tier_label' => $proration['tier_label'],
            'calculation' => ['kind' => 'proration', 'lines' => $proration['lines'], 'formula' => $proration['formula']],
            'period_start' => $proration['charged_from'],
            'period_end' => $proration['period_end'],
            'due_on' => Carbon::today(config('billing.timezone', 'Asia/Kolkata')),
            'open_key' => self::branchKey($branch),
        ], $options));
    }

    /** @param array $fields the charge's priced fields; $options: via, by */
    private function raise(Subscription $s, array $fields, array $options): BillingCharge
    {
        if (! $s->account_id) {
            throw new InvalidArgumentException('This subscription is not linked to a customer account yet, so nothing can be billed to it.');
        }

        return MoneyWrite::during(function () use ($s, $fields, $options) {
            $open = BillingCharge::query()->where('open_key', $fields['open_key'])->lockForUpdate()->first();

            if ($open && $this->sameQuote($open, $fields)) {
                return $open;   // the same demand, already open — never two
            }

            if ($open) {
                $this->assertNotInFlight($open);
                $open->forceFill(['status' => 'superseded', 'open_key' => null])->save();
            }

            // A-7 — there is only ever ONE next term (a late payer pays from today,
            // D-7; nobody is billed for a past period). If the clock moved while a
            // term bill was open (an extension, a comp, a manual date), its key no
            // longer matches: replace it rather than leave two terms owed.
            $staleTerms = in_array($fields['kind'], ['first_term', 'renewal'], true)
                ? BillingCharge::query()->where('account_id', $s->account_id)->pending()
                    ->whereIn('kind', ['first_term', 'renewal'])
                    // Never a REOPENED bill: that is debt for time already granted (E2-G),
                    // not a quote for the next term — replacing it would erase the debt.
                    ->whereNull('reopened_at')
                    ->whereNotNull('open_key')->where('open_key', '!=', $fields['open_key'])
                    ->lockForUpdate()->get()
                : collect();

            foreach ($staleTerms as $stale) {
                $this->assertNotInFlight($stale);
                $stale->forceFill(['status' => 'superseded', 'open_key' => null])->save();
            }

            $charge = BillingCharge::create($fields + [
                'account_id' => $s->account_id,
                'subscription_id' => $s->id,
                'number' => $this->nextNumber(),
                'status' => 'pending',
                'raised_by' => $options['by'] ?? auth()->id(),
                'raised_via' => $options['via'] ?? 'operator',
            ]);

            foreach (array_filter([$open, ...$staleTerms]) as $replaced) {
                $replaced->forceFill(['superseded_by' => $charge->id])->save();
                $this->audit('charge.superseded', "{$replaced->number} replaced by {$charge->number} (₹"
                    . number_format((float) $replaced->amount, 2) . ' → ₹' . number_format((float) $charge->amount, 2) . ')',
                    $charge, $options);
            }

            $this->audit('charge.raised', "Raised {$charge->number}: {$charge->label()} — ₹"
                . number_format((float) $charge->amount, 2), $charge, $options);

            return $charge;
        });
    }

    private function sameQuote(BillingCharge $open, array $fields): bool
    {
        return abs((float) $open->amount - (float) $fields['amount']) < 0.005
            && (int) $open->cycles === (int) $fields['cycles']
            && $open->interval === $fields['interval'];
    }

    /**
     * HostelEase M4 / E3-B — never replace or void a bill the owner may be paying
     * online right now (checkout opened within the window). Outside the window the
     * bill is free again; a payment that still arrives later lands as unapplied.
     */
    private function assertNotInFlight(BillingCharge $charge): void
    {
        if ($charge->isBeingPaidOnline()) {
            throw new InvalidArgumentException("{$charge->number} is being paid online right now (checkout opened at "
                . $charge->gateway_checkout_at->timezone(config('billing.timezone', 'Asia/Kolkata'))->format('H:i')
                . '). Give it a few minutes, then try again.');
        }
    }

    // ---------------------------------------------------------------
    // Void
    // ---------------------------------------------------------------

    /** The charge was never owed (raised in error) or is no longer chased. Reason required. */
    public function void(BillingCharge $charge, string $reason, array $options = []): BillingCharge
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Say why this charge is being voided — the record has to explain it later.');
        }

        $subscription = $charge->subscription ?? $charge->account?->subscription;

        $run = function () use ($charge, $reason, $options) {
            return MoneyWrite::during(function () use ($charge, $reason, $options) {
                $row = BillingCharge::query()->whereKey($charge->getKey())->lockForUpdate()->firstOrFail();

                if (! $row->isPending()) {
                    throw new InvalidArgumentException("{$row->number} is {$row->statusLabel()}, so there is nothing to void.");
                }

                // An operator's own void waits for an online payment in progress; an
                // ending (cancel now, end access) or a grant does not (`force`) — the
                // operator's decision wins, and late money is recorded as unapplied.
                if (! ($options['force'] ?? false)) {
                    $this->assertNotInFlight($row);
                }

                $row->forceFill([
                    'status' => 'void',
                    'open_key' => null,
                    'voided_at' => now(),
                    'voided_by' => $options['by'] ?? auth()->id(),
                    'void_reason' => $reason,
                ])->save();

                $this->audit('charge.voided', "Voided {$row->number} (₹" . number_format((float) $row->amount, 2) . "): {$reason}", $row, $options);

                return $row;
            });
        };

        $row = $subscription ? $this->withLockedSubscription($subscription, fn () => $run()) : MoneyWrite::transaction($run);
        $charge->setRawAttributes($row->getAttributes(), true);

        return $charge;
    }

    /**
     * E2-E / E2-F — void every open charge of the account (optionally only some
     * kinds), e.g. on an immediate cancellation. Returns how many were voided.
     * Runs inside the caller's transaction and lock (the lifecycle's).
     */
    public function voidOpen(Subscription $s, string $reason, ?array $kinds = null, array $options = [], bool $keepReopened = false): int
    {
        $query = BillingCharge::query()->where('account_id', $s->account_id)->pending();

        // A-11 — a REOPENED bill is debt for time already granted (E2-G). Something
        // that only covers the FUTURE (free months) must not erase it.
        if ($keepReopened) {
            $query->whereNull('reopened_at');
        }

        if ($kinds !== null) {
            $query->whereIn('kind', $kinds);
        }

        $count = 0;

        foreach ($query->get() as $charge) {
            $this->void($charge, $reason, $options + ['force' => true]);
            $count++;
        }

        return $count;
    }

    // ---------------------------------------------------------------
    // E3 — the owner opens online checkout for a bill
    // ---------------------------------------------------------------

    /**
     * Attach the bill's ONE Razorpay order (created on first use, reused after —
     * a bill's amount never changes, so its order never goes stale: E3-A) and
     * stamp the time, which starts the "being paid online" window (E3-B).
     *
     * Under the account lock, so two clicks can't create two orders.
     *
     * @param  \Closure(BillingCharge): string  $createOrder  the gateway call, given the locked bill
     */
    public function openCheckout(BillingCharge $charge, \Closure $createOrder): BillingCharge
    {
        $subscription = Subscription::withoutGlobalScopes()->where('account_id', $charge->account_id)->first();

        if (! $subscription) {
            throw new InvalidArgumentException('We could not find your subscription. Please contact us and we will sort it out.');
        }

        return $this->withLockedSubscription($subscription, fn () => MoneyWrite::during(function () use ($charge, $createOrder) {
            $row = BillingCharge::query()->whereKey($charge->getKey())->lockForUpdate()->firstOrFail();

            if (! $row->isPending()) {
                throw new InvalidArgumentException("{$row->number} is already " . mb_strtolower($row->statusLabel()) . ' — there is nothing to pay.');
            }

            $fields = ['gateway_checkout_at' => now()];

            if (! $row->gateway_order_id) {
                $fields['gateway_order_id'] = $createOrder($row);
            }

            $row->forceFill($fields)->save();

            return $row;
        }));
    }

    /** What the account owes right now: the sum of its pending charges. */
    public function owed(?string $accountId): float
    {
        if (! $accountId) {
            return 0.0;
        }

        return round((float) BillingCharge::query()->where('account_id', $accountId)->pending()->sum('amount'), 2);
    }

    /**
     * E2 / 2.7 — every open bill on the platform, aged from its due date: what
     * Today shows as Owed. Charges, not dates: money someone has actually been
     * asked for.
     *
     * @return array{total: float, bills: int, accounts: int, aged: array{fresh: float, month: float, older: float}}
     */
    public function owedSummary(): array
    {
        $today = Carbon::today(config('billing.timezone', 'Asia/Kolkata'));
        $aged = ['fresh' => 0.0, 'month' => 0.0, 'older' => 0.0];
        $upcoming = 0.0;
        $open = BillingCharge::query()->pending()->get(['account_id', 'amount', 'due_on', 'created_at']);

        foreach ($open as $charge) {
            $due = $charge->due_on ?? $charge->created_at;

            // E4-H — raised ahead and not yet due: owed, but nobody is late.
            if ($due && $due->toDateString() > $today->toDateString()) {   // calendar dates, one timezone
                $upcoming = round($upcoming + (float) $charge->amount, 2);

                continue;
            }

            $days = $due ? max(0, (int) Carbon::parse($due->toDateString(), $today->getTimezone())->diffInDays($today, false)) : 0;
            $bucket = $days <= 7 ? 'fresh' : ($days <= 30 ? 'month' : 'older');
            $aged[$bucket] = round($aged[$bucket] + (float) $charge->amount, 2);
        }

        $total = round((float) $open->sum('amount'), 2);

        return [
            'total' => $total,
            'due_now' => round($total - $upcoming, 2),   // due today or earlier, aged below
            'upcoming' => $upcoming,                     // E4-H — coming due (raised ahead)
            'bills' => $open->count(),
            'accounts' => $open->pluck('account_id')->unique()->count(),
            'aged' => $aged,
        ];
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /** E7 — the account's subscription row, locked for this transaction. */
    private function withLockedSubscription(Subscription $subscription, \Closure $fn): mixed
    {
        return MoneyWrite::transaction(function () use ($subscription, $fn) {
            $row = Subscription::withoutGlobalScopes()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();

            return $fn($row);
        });
    }

    /** Same rule as the lifecycle's clock base: never before today, never before the period end. */
    private function clockBase(Subscription $s): Carbon
    {
        $tz = config('billing.timezone', 'Asia/Kolkata');
        $today = Carbon::today($tz);

        $end = $s->current_period_end
            ? Carbon::parse($s->current_period_end->toDateString(), $tz)
            : $today;

        return $end->max($today);
    }

    /** CHG-2026-0001, minted under a lock (same scheme and AUD-A12 ordering as receipts). */
    private function nextNumber(): string
    {
        $prefix = 'CHG-' . now(config('billing.timezone', 'Asia/Kolkata'))->year . '-';

        $latest = BillingCharge::query()
            ->where('number', 'like', $prefix . '%')
            ->orderByRaw('LENGTH(number) DESC')
            ->orderByDesc('number')
            ->lockForUpdate()
            ->value('number');

        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function audit(string $action, string $description, BillingCharge $charge, array $options): void
    {
        AdminAuditLog::record($action, $description, $charge->tenant_id, array_filter([
            'account_id' => $charge->account_id,
            'charge' => $charge->number,
            'amount' => (float) $charge->amount,
            'kind' => $charge->kind,
            // E1.12 — name who did it when nobody in the panel did.
            'actor' => $options['actor'] ?? null,
        ], fn ($v) => $v !== null));
    }
}

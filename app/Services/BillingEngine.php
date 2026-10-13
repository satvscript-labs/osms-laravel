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
        $end = BillingCycle::add($start, $interval, $cycles);
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

            return $this->raise($s, [
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
            ], $options);
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
        return MoneyWrite::during(function () use ($s, $fields, $options) {
            $open = BillingCharge::query()->where('open_key', $fields['open_key'])->lockForUpdate()->first();

            if ($open && $this->sameQuote($open, $fields)) {
                return $open;   // the same demand, already open — never two
            }

            if ($open) {
                $this->assertNotInFlight($open);
                $open->forceFill(['status' => 'superseded', 'open_key' => null])->save();
            }

            $charge = BillingCharge::create($fields + [
                'account_id' => $s->account_id,
                'subscription_id' => $s->id,
                'number' => $this->nextNumber(),
                'status' => 'pending',
                'raised_by' => $options['by'] ?? auth()->id(),
                'raised_via' => $options['via'] ?? 'operator',
            ]);

            if ($open) {
                $open->forceFill(['superseded_by' => $charge->id])->save();
                $this->audit('charge.superseded', "{$open->number} replaced by {$charge->number} (₹"
                    . number_format((float) $open->amount, 2) . ' → ₹' . number_format((float) $charge->amount, 2) . ')',
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

    /** HostelEase M4 — never replace a charge the gateway may be collecting right now (E3). */
    private function assertNotInFlight(BillingCharge $charge): void
    {
        if ($charge->gateway_order_id) {
            throw new InvalidArgumentException("A payment for {$charge->number} is being processed online. Give it a minute, then try again.");
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

                $this->assertNotInFlight($row);

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

        $row = $subscription ? $this->withLockedSubscription($subscription, fn () => $run()) : DB::transaction($run);
        $charge->setRawAttributes($row->getAttributes(), true);

        return $charge;
    }

    /**
     * E2-E / E2-F — void every open charge of the account (optionally only some
     * kinds), e.g. on an immediate cancellation. Returns how many were voided.
     * Runs inside the caller's transaction and lock (the lifecycle's).
     */
    public function voidOpen(Subscription $s, string $reason, ?array $kinds = null, array $options = []): int
    {
        $query = BillingCharge::query()->where('account_id', $s->account_id)->pending();

        if ($kinds !== null) {
            $query->whereIn('kind', $kinds);
        }

        $count = 0;

        foreach ($query->get() as $charge) {
            $this->void($charge, $reason, $options);
            $count++;
        }

        return $count;
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
        $open = BillingCharge::query()->pending()->get(['account_id', 'amount', 'due_on', 'created_at']);

        foreach ($open as $charge) {
            $due = $charge->due_on ?? $charge->created_at;
            $days = $due ? max(0, (int) Carbon::parse($due->toDateString())->diffInDays($today, false)) : 0;
            $bucket = $days <= 7 ? 'fresh' : ($days <= 30 ? 'month' : 'older');
            $aged[$bucket] = round($aged[$bucket] + (float) $charge->amount, 2);
        }

        return [
            'total' => round((float) $open->sum('amount'), 2),
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
        return DB::transaction(function () use ($subscription, $fn) {
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

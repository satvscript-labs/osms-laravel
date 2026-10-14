<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\Metrics;
use App\Support\Mrr;
use Illuminate\View\View;

/**
 * P2 — "Today": the operator's home.
 *
 * Answers three questions IN THIS ORDER (playbook §4):
 *   1. how is the money   2. how healthy is the base   3. what needs me today
 * The third is a WORKLIST of actionable rows, not a chart.
 *
 * BUG-P09 — the old version loaded every subscription into memory and ran five
 * PHP filters over them, plus a platform-wide Order::count(). Everything here
 * aggregates in SQL and only the worklist (bounded, by definition small) is
 * hydrated.
 */
class DashboardController extends Controller
{
    /** How far ahead the "expiring soon" worklist looks. */
    private const HORIZON_DAYS = 14;

    public function index(): View
    {
        // Rule 7 — a billing date is a day in the BILLING timezone (UTC was a day off before 05:30 IST).
        $today = \Illuminate\Support\Carbon::today(config('billing.timezone', 'Asia/Kolkata'));
        $horizon = $today->copy()->addDays(self::HORIZON_DAYS);

        // ---- 1. Money -------------------------------------------------
        // MRR needs the plan + override to resolve a price, so hydrate only
        // the ACTIVE ones (bounded by paying customers, not by all history).
        // AUD-A05 — `account.stores` is eager-loaded because Mrr now asks
        // whether the customer has a store still open. Loading it here keeps
        // that one query for the whole page instead of one per customer.
        $active = Subscription::withoutGlobalScopes()
            ->with(['plan', 'account.stores:id,account_id,store_status,is_billable'])
            ->where('status', 'active')
            ->get();

        $mrr = $active->sum(fn ($s) => Mrr::monthlyValue($s));
        $comped = $active->filter(fn ($s) => $s->override_kind === 'comp' && $s->hasActiveOverride())->count();

        // ---- 2. Base health -------------------------------------------
        $byStatus = Subscription::withoutGlobalScopes()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        // QA-06 — a suspension is stored as past_due; counted over the SAME rows as
        // $byStatus so the subtraction below is exact (one query, not two).
        $suspended = Subscription::withoutGlobalScopes()->where('status', 'past_due')->suspended()->count();

        // Collected this month and all time in ONE query (the page has a query budget).
        $collected = SubscriptionInvoice::withoutGlobalScopes()->collected()
            ->selectRaw('COALESCE(SUM(amount), 0) AS total, COALESCE(SUM(CASE WHEN paid_at BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS month',
                [now()->startOfMonth(), now()->endOfMonth()])
            ->first();

        $stats = [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'comped' => $comped,
            'collected_month' => (float) $collected->month,
            'collected_all' => (float) $collected->total,
            'accounts' => Account::count(),
            'stores' => Tenant::count(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'trialing' => (int) ($byStatus['trialing'] ?? 0),
            // QA-06 — owed money only; anyone we paused is counted on its own.
            'past_due' => max(0, (int) ($byStatus['past_due'] ?? 0) - $suspended),
            'suspended' => $suspended,
            'canceled' => (int) ($byStatus['canceled'] ?? 0),
        ];

        // ---- 3. What needs me today -----------------------------------
        // A bounded worklist of rows the operator can act on, ordered by
        // urgency. Deliberately not a chart.
        $worklist = Subscription::withoutGlobalScopes()
            ->with(['account:id,name,display_name,billing_email,billing_phone,owner_user_id,supervised', 'account.owner:id,name', 'account.stores:id,account_id,store_status,is_billable', 'plan'])
            ->whereNotNull('account_id')
            ->where(function ($q) use ($horizon) {
                $q->where('status', 'past_due')
                    ->orWhere(function ($w) use ($horizon) {
                        $w->whereIn('status', ['trialing', 'active'])
                            ->whereNotNull('current_period_end')
                            ->whereDate('current_period_end', '<=', $horizon->toDateString());
                    });
            })
            ->orderByRaw("CASE status WHEN 'past_due' THEN 1 WHEN 'trialing' THEN 2 ELSE 3 END")
            ->orderBy('current_period_end')
            ->limit(25)
            ->get();

        // E4-I — what each one owes and when they were last reminded: one query each.
        $ids = $worklist->pluck('account_id');
        $openBills = \App\Models\BillingCharge::query()->pending()->whereIn('account_id', $ids)
            ->selectRaw('account_id, SUM(amount) AS owed')->groupBy('account_id')->pluck('owed', 'account_id');
        $reminded = \App\Models\BillingReminder::query()->whereIn('account_id', $ids)
            ->orderByDesc('sent_at')->get()->unique('account_id')->keyBy('account_id');
        $prices = app(\App\Services\PriceResolver::class);

        $worklist = $worklist
            ->map(function (Subscription $s) use ($today, $openBills, $reminded, $prices) {
                $end = $s->current_period_end ? \Illuminate\Support\Carbon::parse($s->current_period_end->toDateString(), $today->getTimezone()) : null;
                $account = $s->account;
                $owed = isset($openBills[$s->account_id]) ? (float) $openBills[$s->account_id] : null;
                $managed = $s->override_kind && ! in_array($s->override_kind, ['manual_renewal', 'suspension'], true)
                    && ($s->override_until === null || ($end && $s->override_until->toDateString() >= $end->toDateString()));
                $chase = $account && ! $s->isSuspended() && ! $managed && ! $s->cancel_at_period_end && $s->status !== 'trialing';
                $amount = $owed ?? ($chase ? $prices->renewalPrice($s) : null);
                $last = $reminded[$s->account_id] ?? null;

                return [
                    'account' => $s->account?->displayName() ?? '—',
                    'email' => $s->account?->billing_email,
                    'status' => $s->displayStatus(),
                    'access' => $s->accessState(),
                    'ends' => $end?->format('d M Y'),
                    'days_left' => $end ? (int) $today->diffInDays($end, false) : null,
                    // QA-06 — a suspension needs a decision, not a payment chase.
                    'why' => match (true) {
                        $s->isSuspended() => 'Suspended by you',
                        // E4-F — a period you gave: the next step is yours, not a chase.
                        $managed && $s->override_kind === 'comp' => 'Free period ends — decide',
                        $managed && $s->override_kind === 'extension' => 'Extension ends — decide',
                        $managed || (bool) $s->cancel_at_period_end => 'Cancels — no renewal',
                        $s->status === 'past_due' => 'Payment overdue',
                        $s->status === 'trialing' => 'Trial ending',
                        default => 'Renewal due',
                    },
                    'url' => $s->account ? route('superadmin.accounts.show', $s->account_id) : null,
                    // E4-I — the amount, the last reminder, and the WhatsApp nudge.
                    'amount' => $chase ? $amount : null,
                    'billed' => $owed !== null,
                    'reminded' => $last ? $last->label() . ' ' . $last->sent_at->diffForHumans() : null,
                    'whatsapp' => $chase ? \App\Support\RenewalNudge::whatsappUrl($account, $amount, $end, $s->status === 'past_due' || ($end && $end->lt($today))) : null,
                    'no_phone' => $chase && blank($account?->billing_phone),
                ];
            })
            ->filter(fn ($r) => $r['url'] !== null)
            ->values();

        // Stores that exist but have no account yet — only possible before the
        // backfill runs. Surfaced so a half-migrated deploy is visible, not silent.
        $unbackfilled = Tenant::whereNull('account_id')->count();

        $metrics = app(Metrics::class);

        return view('superadmin.dashboard', [
            'stats' => $stats,
            'worklist' => $worklist,
            'unbackfilled' => $unbackfilled,
            // P6 / §8 — the two metrics the one ledger and the account layer
            // made possible. Both state their own limits on the surface.
            'collection' => $metrics->collection(),
            // E3 — money received online that is on no bill: a person must decide.
            'unapplied' => (function () {
                $row = \App\Models\UnappliedPayment::open()->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount), 0) AS t')->first();

                return ['count' => (int) $row->c, 'total' => (float) $row->t];   // one query
            })(),
            // E2 — bills raised and not yet paid, by age.
            'owed' => app(\App\Services\BillingEngine::class)->owedSummary(),
            'churn' => $metrics->churn(),
            'recent' => Account::query()
                ->with('subscription')
                ->withCount('stores')
                ->latest()
                ->limit(6)
                ->get(),
        ]);
    }
}

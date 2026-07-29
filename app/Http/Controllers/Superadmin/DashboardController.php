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
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays(self::HORIZON_DAYS);

        // ---- 1. Money -------------------------------------------------
        // MRR needs the plan + override to resolve a price, so hydrate only
        // the ACTIVE ones (bounded by paying customers, not by all history).
        $active = Subscription::withoutGlobalScopes()
            ->with('plan')
            ->where('status', 'active')
            ->get();

        $mrr = $active->sum(fn ($s) => Mrr::monthlyValue($s));
        $comped = $active->filter(fn ($s) => $s->override_kind === 'comp' && $s->hasActiveOverride())->count();

        $live = SubscriptionInvoice::withoutGlobalScopes()->whereNull('reversed_at');

        // ---- 2. Base health -------------------------------------------
        $byStatus = Subscription::withoutGlobalScopes()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $stats = [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'comped' => $comped,
            'collected_month' => (float) (clone $live)
                ->where('method', '!=', 'comp')
                ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('amount'),
            'collected_all' => (float) (clone $live)->where('method', '!=', 'comp')->sum('amount'),
            'accounts' => Account::count(),
            'stores' => Tenant::count(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'trialing' => (int) ($byStatus['trialing'] ?? 0),
            'past_due' => (int) ($byStatus['past_due'] ?? 0),
            'canceled' => (int) ($byStatus['canceled'] ?? 0),
        ];

        // ---- 3. What needs me today -----------------------------------
        // A bounded worklist of rows the operator can act on, ordered by
        // urgency. Deliberately not a chart.
        $worklist = Subscription::withoutGlobalScopes()
            ->with(['account:id,name,display_name,billing_email', 'plan'])
            ->whereNotNull('account_id')
            ->where(function ($q) use ($horizon) {
                $q->where('status', 'past_due')
                    ->orWhere(function ($w) use ($horizon) {
                        $w->whereIn('status', ['trialing', 'active'])
                            ->whereNotNull('current_period_end')
                            ->where('current_period_end', '<=', $horizon->toDateString());
                    });
            })
            ->orderByRaw("CASE status WHEN 'past_due' THEN 1 WHEN 'trialing' THEN 2 ELSE 3 END")
            ->orderBy('current_period_end')
            ->limit(25)
            ->get()
            ->map(function (Subscription $s) use ($today) {
                $end = $s->current_period_end;

                return [
                    'account' => $s->account?->displayName() ?? '—',
                    'email' => $s->account?->billing_email,
                    'status' => $s->status,
                    'access' => $s->accessState(),
                    'ends' => $end?->format('d M Y'),
                    'days_left' => $end ? (int) $today->diffInDays($end->startOfDay(), false) : null,
                    'why' => $s->status === 'past_due'
                        ? 'Payment overdue'
                        : ($s->status === 'trialing' ? 'Trial ending' : 'Renewal due'),
                    'url' => $s->account ? route('superadmin.accounts.show', $s->account_id) : null,
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

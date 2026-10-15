<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Subscription;
use App\Models\BillingCharge;
use App\Models\SubscriptionInvoice;
use App\Services\BillingEngine;
use App\Services\PriceResolver;
use App\Services\StoreClosure;
use App\Support\Mrr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * P2 / REQ-12 — "Customers": one row per ACCOUNT, and the Customer 360.
 *
 * The panel's daily surface. Answers "who pays me, how much, and who needs
 * chasing?" — the Stores surface answers the operational question separately.
 *
 * BUG-P09 — everything here paginates and aggregates in SQL. The old store
 * list did `->get()` on every tenant then ran a query per row inside a map;
 * this assumes 100x the current count, per playbook §4.
 */
class AccountController extends Controller
{
    /** Rows per page — dense enough for an operator, bounded for SQL. */
    private const PER_PAGE = 25;

    public function index(Request $request): View|JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', 'attention');

        $accounts = $this->query($search, $filter)->paginate(self::PER_PAGE)->withQueryString();

        // Live-search endpoint for the Alpine list (debounced ~220ms).
        if ($request->wantsJson()) {
            return response()->json([
                'rows' => $accounts->getCollection()->map(fn ($a) => $this->row($a))->values(),
                'total' => $accounts->total(),
                'has_more' => $accounts->hasMorePages(),
            ]);
        }

        return view('superadmin.accounts.index', [
            'accounts' => $accounts,
            'rows' => $accounts->getCollection()->map(fn ($a) => $this->row($a)),
            'search' => $search,
            'filter' => $filter,
            'counts' => $this->filterCounts(),
        ]);
    }

    /** Customer 360 — one account, every commercial fact, read-only in P2. */
    public function show(Account $account): View
    {
        $account->load([
            'subscription.plan',
            // Eager-loaded with counts so the Stores tab is one query, not N.
            'stores' => fn ($q) => $q->withCount(['customers', 'orders', 'users'])->orderBy('store_name'),
            // P5 — the Access tab lists every person who can sign in. Eager-loaded
            // (admins first) so the tab is one more query, not one per store.
            'stores.users' => fn ($q) => $q->orderByRaw("role = 'store_admin' desc")->orderBy('name'),
            'owner',
        ]);

        $subscription = $account->subscription;

        $ledger = SubscriptionInvoice::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->with(['tenant:id,store_name', 'charge.tenant:id,store_name'])
            ->newestFirst()
            ->limit(50)
            ->get();

        // E2 — what this customer has been asked for: open first, then the recent closed.
        $charges = BillingCharge::query()
            ->where('account_id', $account->id)
            ->with('tenant:id,store_name')
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('superadmin.accounts.show', [
            'account' => $account,
            'subscription' => $subscription,
            'charges' => $charges,
            // E3 — this customer's money received online that is on no bill yet.
            'unapplied' => \App\Models\UnappliedPayment::open()->where('account_id', $account->id)->orderByDesc('created_at')->get(),
            'owed' => app(BillingEngine::class)->owed($account->id),
            'price' => $subscription
                ? app(PriceResolver::class)->breakdown($subscription)
                : null,
            // QA-05 — Renew now pre-fills the NEXT period's price, per interval.
            'renewPrices' => $subscription ? [
                'monthly' => app(PriceResolver::class)->renewalPrice($subscription, 'monthly'),
                'yearly' => app(PriceResolver::class)->renewalPrice($subscription, 'yearly'),
            ] : null,
            'mrr' => Mrr::monthlyValue($subscription),
            'ledger' => $ledger,
            // Lifetime value is one SUM over the one ledger — reversed rows excluded.
            'lifetime' => (float) SubscriptionInvoice::withoutGlobalScopes()
                ->where('account_id', $account->id)
                ->collected()
                ->sum('amount'),
            /*
             * P5 / row 16 — "N rows will be destroyed", for the purge confirmation.
             *
             * Counted only for stores that are actually closed, because the count
             * sweeps fifteen tables and no live store can be purged anyway. An
             * operator about to delete a shop's history deserves to see its size
             * first; an operator looking at a healthy customer deserves not to
             * pay for that query.
             */
            'storeRowCounts' => $account->stores
                ->filter->isClosed()
                ->mapWithKeys(fn ($store) => [
                    $store->id => array_sum(app(StoreClosure::class)->inventory($store->id)),
                ]),
            // E5-A / FB-14 — what closing each open store would do to their bill.
            'closureImpact' => $account->stores
                ->reject->isClosed()
                ->mapWithKeys(fn ($store) => [$store->id => app(StoreClosure::class)->impact($store)]),
            'auditLogs' => \App\Models\AdminAuditLog::query()
                ->whereIn('tenant_id', $account->stores->pluck('id'))
                ->with('admin:id,name')
                ->latest()
                ->limit(30)
                ->get(),
        ]);
    }

    /**
     * The list query — filtered and sorted in SQL.
     *
     * Default sort is "what needs attention" (playbook §4), not alphabetical
     * and not newest-first: past-due first, then in-grace, then expiring soon.
     */
    private function query(string $search, string $filter)
    {
        $q = Account::query()
            ->select('accounts.*')
            ->with(['subscription.plan'])
            ->withCount('stores')
            // One correlated sub-select beats a query per row in a map (BUG-P09).
            ->withSum(['invoices as lifetime_value' => fn ($i) => $i->whereNull('reversed_at')], 'amount')
            ->leftJoin('subscriptions', 'subscriptions.account_id', '=', 'accounts.id');

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('accounts.name', 'like', $like)
                    ->orWhere('accounts.billing_email', 'like', $like)
                    ->orWhere('accounts.display_name', 'like', $like)
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('tenants')
                        ->whereColumn('tenants.account_id', 'accounts.id')
                        ->where('tenants.store_name', 'like', $like));
            });
        }

        match ($filter) {
            'trialing' => $q->where('subscriptions.status', 'trialing'),
            'paid' => $q->where('subscriptions.status', 'active'),
            // QA-06 — a suspension is ours, not a lapse; it stays under "Needs me".
            'lapsed' => $q->where(fn ($w) => $w->where('subscriptions.status', 'canceled')
                ->orWhere(fn ($o) => $o->where('subscriptions.status', 'past_due')
                    ->whereNotIn('subscriptions.id', Subscription::withoutGlobalScopes()->suspended()->select('id')))),
            'attention' => $q->where(function ($w) {
                $w->whereIn('subscriptions.status', ['past_due'])
                    ->orWhere('subscriptions.current_period_end', '<=', now()->addDays(14)->toDateString());
            }),
            default => null, // 'all'
        };

        return $q->orderByRaw(
            "CASE subscriptions.status
                WHEN 'past_due' THEN 1
                WHEN 'trialing' THEN 2
                WHEN 'active'   THEN 3
                ELSE 4 END"
        )->orderByRaw('subscriptions.current_period_end IS NULL')
            ->orderBy('subscriptions.current_period_end')
            ->orderBy('accounts.name');
    }

    /** Counts for the segmented filter — one grouped query, never N. */
    private function filterCounts(): array
    {
        $byStatus = Subscription::withoutGlobalScopes()
            ->whereNotNull('account_id')
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'all' => Account::count(),
            'trialing' => (int) ($byStatus['trialing'] ?? 0),
            'paid' => (int) ($byStatus['active'] ?? 0),
            'lapsed' => (int) ($byStatus['past_due'] ?? 0) + (int) ($byStatus['canceled'] ?? 0)
                - Subscription::withoutGlobalScopes()->whereNotNull('account_id')->where('status', 'past_due')->suspended()->count(),
        ];
    }

    /** One list row — the same shape server-rendered and live, so they can't drift. */
    private function row(Account $account): array
    {
        $s = $account->subscription;
        $access = $s?->accessState() ?? 'locked';

        return [
            'id' => $account->id,
            'name' => $account->displayName(),
            'email' => $account->billing_email,
            'stores' => (int) ($account->stores_count ?? 0),
            'status' => $s?->displayStatus() ?? 'none',   // QA-06
            'access' => $access,
            'renews' => $s?->current_period_end?->format('d M Y'),
            'days_left' => $s?->current_period_end
                ? (int) now()->startOfDay()->diffInDays($s->current_period_end->startOfDay(), false)
                : null,
            'price' => $s ? app(PriceResolver::class)->effectivePrice($s) : 0.0,
            'interval' => $s?->interval,
            'bespoke' => (bool) $s?->hasNegotiatedPrice(),
            'override' => $s?->hasActiveOverride() ? $s->override_kind : null,
            'lifetime' => (float) ($account->lifetime_value ?? 0),
            'url' => route('superadmin.accounts.show', $account),
        ];
    }
}

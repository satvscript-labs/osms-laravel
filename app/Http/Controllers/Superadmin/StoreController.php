<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * P2 — "Stores": the cross-account operational sweep.
 *
 * Deliberately a SEPARATE surface from Customers. They answer different
 * questions (06 §8):
 *   Customers — "who pays me, how much, who needs chasing?"  (commercial)
 *   Stores    — "which shops are live, quiet, or in trouble?" (operational)
 *
 * Sorted by activity, filterable by account. Read-only in P2.
 */
class StoreController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', 'all');

        $stores = Tenant::query()
            ->with(['account:id,name,display_name'])
            // Counts in SQL, not a query per row (BUG-P09).
            ->withCount(['customers', 'orders', 'users'])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('store_name', 'like', $like)
                        ->orWhereHas('account', fn ($a) => $a->where('name', 'like', $like));
                });
            })
            ->when($filter === 'suspended', fn ($q) => $q->where('store_status', 'suspended'))
            ->when($filter === 'unbilled', fn ($q) => $q->where('is_billable', false))
            ->when($filter === 'quiet', fn ($q) => $q->doesntHave('orders'))
            ->orderByDesc('orders_count')
            ->orderBy('store_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('superadmin.stores.index', [
            'stores' => $stores,
            'search' => $search,
            'filter' => $filter,
            'counts' => [
                'all' => Tenant::count(),
                'suspended' => Tenant::where('store_status', 'suspended')->count(),
                'unbilled' => Tenant::where('is_billable', false)->count(),
                'quiet' => Tenant::doesntHave('orders')->count(),
            ],
        ]);
    }

    /**
     * One store — operational facts, with a breadcrumb back to the account
     * that owns it. Commercial levers live on the Customer 360, not here:
     * money belongs to the payer, not the shop.
     */
    public function show(Tenant $tenant): View
    {
        $tenant->load([
            'account.subscription',
            'users' => fn ($q) => $q->orderByRaw("role = 'store_admin' desc")->orderBy('name'),
            'whatsappConfig',
        ]);

        $tenant->loadCount(['customers', 'orders', 'inventory']);

        return view('superadmin.stores.show', [
            'store' => $tenant,
            'account' => $tenant->account,
            'auditLogs' => AdminAuditLog::where('tenant_id', $tenant->id)
                ->with('admin:id,name')
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }
}

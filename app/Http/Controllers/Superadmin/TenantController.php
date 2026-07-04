<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Tenant;
use App\Support\Mrr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ST-Admin (S11) — the store directory and per-store control surface.
 */
class TenantController extends Controller
{
    /** All stores with the metrics the owner filters/scans by. */
    public function index(): View
    {
        $tenants = Tenant::with('subscription')
            ->withCount(['users', 'customers', 'orders'])
            ->withSum('subscriptionInvoices as revenue_total', 'amount')
            ->latest()
            ->get()
            ->map(function (Tenant $t) {
                $sub = $t->subscription;
                $owner = $t->users()->where('role', 'store_admin')->orderBy('id')->first();

                return [
                    'id' => $t->id,
                    'name' => $t->store_name,
                    'owner_email' => $owner?->email,
                    'status' => $sub?->status ?? 'none',
                    'access' => $sub?->accessState() ?? 'locked',
                    'interval' => $sub?->interval,
                    'trial_days_left' => $sub?->trialDaysLeft(),
                    'period_end' => $sub?->current_period_end?->format('d M Y'),
                    'manual' => (bool) ($sub?->manual),
                    'mrr' => Mrr::monthlyValue($sub),
                    'revenue_total' => (float) ($t->revenue_total ?? 0),
                    'users' => $t->users_count,
                    'orders' => $t->orders_count,
                    'created' => $t->created_at?->format('d M Y'),
                    'url' => route('superadmin.tenants.show', $t),
                ];
            })
            ->values();

        return view('superadmin.tenants.index', ['tenants' => $tenants]);
    }

    /** Full control panel for a single store. */
    public function show(Tenant $tenant): View
    {
        $tenant->load([
            'subscription',
            'users' => fn ($q) => $q->orderByRaw("role = 'store_admin' desc")->orderBy('name'),
            'subscriptionInvoices' => fn ($q) => $q->latest('paid_at')->latest(),
            'auditLogs' => fn ($q) => $q->with('admin')->latest()->limit(30),
        ]);

        $owner = $tenant->users->firstWhere('role', 'store_admin');

        return view('superadmin.tenants.show', [
            'tenant' => $tenant,
            'subscription' => $tenant->subscription,
            'owner' => $owner,
            'plans' => config('billing.plans'),
        ]);
    }

    /** Save the private internal memo about a store. */
    public function updateNotes(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $tenant->update(['internal_notes' => $validated['internal_notes']]);

        AdminAuditLog::record(
            'tenant.notes_updated',
            "Updated internal notes for {$tenant->store_name}",
            $tenant->id,
        );

        return back()->with('status', 'Notes saved.');
    }
}

<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * P2 / REQ-5 — the one ledger, across every account.
 *
 * The payoff of P1's channel-agnostic ledger: revenue, comps and reversals are
 * ONE query against ONE table, never a union of two subsystems. Cash, UPI,
 * bank transfer, Razorpay and ₹0 comps all appear here side by side.
 */
class BillingController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $method = (string) $request->query('method', 'all');
        $search = trim((string) $request->query('q', ''));

        $rows = SubscriptionInvoice::withoutGlobalScopes()
            ->with(['account:id,name,display_name', 'tenant:id,store_name', 'recordedBy:id,name'])
            ->when($method !== 'all', fn ($q) => $q->where('method', $method))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('receipt_no', 'like', $like)
                        ->orWhere('reference', 'like', $like)
                        ->orWhere('razorpay_payment_id', 'like', $like)
                        ->orWhereHas('account', fn ($a) => $a->where('name', 'like', $like));
                });
            })
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Aggregates in SQL. Reversed rows never count toward collected revenue.
        $live = SubscriptionInvoice::withoutGlobalScopes()->whereNull('reversed_at');

        return view('superadmin.billing.index', [
            'rows' => $rows,
            'method' => $method,
            'search' => $search,
            'totals' => [
                'collected' => (float) (clone $live)->where('method', '!=', 'comp')->sum('amount'),
                'comped' => (int) (clone $live)->where('method', 'comp')->count(),
                'reversed' => (int) SubscriptionInvoice::withoutGlobalScopes()->whereNotNull('reversed_at')->count(),
                'this_month' => (float) (clone $live)
                    ->where('method', '!=', 'comp')
                    ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->sum('amount'),
            ],
            'methodCounts' => SubscriptionInvoice::withoutGlobalScopes()
                ->selectRaw('method, COUNT(*) as c')
                ->groupBy('method')
                ->pluck('c', 'method'),
        ]);
    }
}

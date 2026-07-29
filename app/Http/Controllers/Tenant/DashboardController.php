<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $today = now()->startOfDay();
        $threeDaysAgo = now()->subDays(3);

        // Today's sales — delivered orders placed today (by created_at, not
        // updated_at, which drifts on any later save; see AnalyticsController).
        $todaySales = (float) Order::where('status', 'delivered')
            ->where('created_at', '>=', $today)
            ->sum('total_amount');

        $pendingCount = Order::where('status', 'pending')->count();
        $readyCount = Order::where('status', 'ready_for_pickup')->count();

        // Low stock (compare two columns).
        // PERF-05 — count in SQL and fetch only the 5 shown, instead of loading
        // every low-stock row into PHP just to count it.
        $lowStockQuery = Inventory::where('is_tracked', true)->whereColumn('stock_qty', '<=', 'min_alert_qty');
        $lowStockCount = (clone $lowStockQuery)->count();
        $lowStock = $lowStockQuery->orderBy('stock_qty')->limit(5)->get();

        // Overdue ready-for-pickup orders (waiting > 3 days).
        // WEB-01 — measured from ready_at (when it entered ready_for_pickup), not
        // updated_at, which any later save (a payment, an edit) would reset. Falls
        // back to updated_at for rows predating the column.
        $overduePickups = Order::with('customer:id,name')
            ->where('status', 'ready_for_pickup')
            ->whereRaw('COALESCE(ready_at, updated_at) < ?', [$threeDaysAgo])
            ->orderByRaw('COALESCE(ready_at, updated_at)')
            ->limit(8)
            ->get()
            ->map(function (Order $o) {
                $since = $o->ready_at ?? $o->updated_at;

                return [
                    'id' => $o->id,
                    'customer_name' => $o->customer?->name,
                    'total_amount' => (float) $o->total_amount,
                    'days' => (int) $since->diffInDays(now()),
                ];
            });

        // FT-Fulfillment — special orders in the lab that are due today or overdue
        // to prepare (by their promised estimated_ready_at).
        $dueToPrepare = Order::with('customer:id,name')
            ->where('status', 'pending')
            ->where('fulfillment_type', 'special')
            ->whereNotNull('estimated_ready_at')
            ->whereDate('estimated_ready_at', '<=', $today)
            ->orderBy('estimated_ready_at')
            ->limit(8)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'customer_name' => $o->customer?->name,
                'total_amount' => (float) $o->total_amount,
                'overdue_days' => (int) $o->estimated_ready_at->startOfDay()->diffInDays($today),
            ]);

        $subscription = Subscription::first();
        $subscriptionPastDue = $subscription?->isPastDue() ?? false;

        // First-run guidance: a brand-new store with nothing entered yet.
        $isFirstRun = ! Inventory::exists() && ! Customer::exists() && ! Order::exists();

        return view('tenant.dashboard', compact(
            'todaySales', 'pendingCount', 'readyCount',
            'lowStock', 'lowStockCount', 'overduePickups', 'dueToPrepare', 'subscriptionPastDue',
            'isFirstRun',
        ));
    }
}

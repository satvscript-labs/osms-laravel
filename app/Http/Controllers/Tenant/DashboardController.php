<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
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

        // Today's delivered sales
        $todaySales = (float) Order::where('status', 'delivered')
            ->where('updated_at', '>=', $today)
            ->sum('total_amount');

        $pendingCount = Order::where('status', 'pending')->count();
        $readyCount = Order::where('status', 'ready_for_pickup')->count();

        // Low stock (compare two columns)
        $lowStockItems = Inventory::whereColumn('stock_qty', '<=', 'min_alert_qty')
            ->orderBy('stock_qty')
            ->get();
        $lowStockCount = $lowStockItems->count();
        $lowStock = $lowStockItems->take(5);

        // Overdue ready-for-pickup orders (waiting > 3 days)
        $overduePickups = Order::with('customer:id,name')
            ->where('status', 'ready_for_pickup')
            ->where('updated_at', '<', $threeDaysAgo)
            ->limit(8)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'customer_name' => $o->customer?->name,
                'total_amount' => (float) $o->total_amount,
                'days' => (int) $o->updated_at->diffInDays(now()),
            ]);

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

        return view('tenant.dashboard', compact(
            'todaySales', 'pendingCount', 'readyCount',
            'lowStock', 'lowStockCount', 'overduePickups', 'dueToPrepare', 'subscriptionPastDue',
        ));
    }
}

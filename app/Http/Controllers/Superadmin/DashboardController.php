<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Mrr;
use Illuminate\View\View;

/**
 * ST-Admin (S11) — platform overview: the metrics the SaaS owner needs at a
 * glance (subscribers, revenue, trials at risk, churn).
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        $subscriptions = Subscription::with('tenant')->get();

        $active = $subscriptions->filter(fn ($s) => $s->status === 'active');
        $trialing = $subscriptions->filter(fn ($s) => $s->status === 'trialing' && $s->hasAccess());

        // Trials ending within 3 days — proactive-outreach list.
        $atRisk = $subscriptions
            ->filter(fn ($s) => $s->status === 'trialing' && $s->trialDaysLeft() !== null && $s->trialDaysLeft() <= 3)
            ->sortBy(fn ($s) => $s->trialDaysLeft())
            ->take(10);

        // Churn: subscriptions moved to canceled in the last 30 days.
        $churned = $subscriptions
            ->filter(fn ($s) => $s->status === 'canceled' && $s->updated_at?->gte(now()->subDays(30)))
            ->count();

        // WEB-03 — comped (`manual`) subscriptions contribute 0 to MRR, so the figure
        // reflects real revenue. They're counted separately rather than hidden.
        $mrr = $active->sum(fn ($s) => Mrr::monthlyValue($s));
        $comped = $active->filter(fn ($s) => (bool) $s->manual)->count();

        $stats = [
            'stores' => $subscriptions->count(),
            'active' => $active->count(),
            'trialing' => $trialing->count(),
            'mrr' => $mrr,
            'comped' => $comped,
            'churn30' => $churned,
            'users' => User::where('role', '!=', 'superadmin')->count(),
            'orders' => Order::count(),
        ];

        $recent = Tenant::with('subscription')->withCount('users')->latest()->take(6)->get();

        return view('superadmin.dashboard', [
            'stats' => $stats,
            'atRisk' => $atRisk,
            'recent' => $recent,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\View\View;

/**
 * P2 / REQ-4 — list prices, and who is on a bespoke one.
 *
 * Read-only in P2; editing lands in P3 with the rest of the operator actions.
 * The value here even read-only is answering "what do we charge, and who is
 * paying something else?" — which was previously unanswerable without reading
 * config and the database together.
 */
class PlanController extends Controller
{
    public function index(): View
    {
        $plans = Plan::query()
            ->withCount('subscriptions')
            ->orderBy('sort_order')
            ->get();

        // Every store on a hand-agreed price. Small by nature — these are the
        // exceptions — so a full load is honest here, unlike a store list.
        $bespoke = Subscription::withoutGlobalScopes()
            ->whereNotNull('negotiated_price')
            ->with(['account:id,name,display_name', 'plan:id,name,monthly_price,yearly_price', 'negotiatedBy:id,name'])
            ->orderByDesc('negotiated_at')
            ->get();

        return view('superadmin.plans.index', [
            'plans' => $plans,
            'bespoke' => $bespoke,
        ]);
    }
}

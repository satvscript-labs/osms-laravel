<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\View\View;

/**
 * PRIV-04 — the store's own activity trail (read-only, store-admin only). Shows who
 * changed or deleted records, for grievance handling and internal oversight.
 */
class ActivityController extends Controller
{
    public function index(): View
    {
        $logs = ActivityLog::with('user:id,name')
            ->latest()
            ->paginate(50);

        return view('tenant.activity.index', ['logs' => $logs]);
    }
}

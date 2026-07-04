<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\View\View;

/**
 * ST-Admin (S11) — the full platform audit trail (read-only).
 */
class AuditLogController extends Controller
{
    public function index(): View
    {
        $logs = AdminAuditLog::with(['admin', 'tenant'])
            ->latest()
            ->paginate(50);

        return view('superadmin.audit.index', ['logs' => $logs]);
    }
}

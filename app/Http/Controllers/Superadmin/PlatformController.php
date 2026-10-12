<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\PlatformSetting;
use App\Support\PlatformHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * P4 — Platform: the operator's knobs, and the health of the automated lane.
 *
 * Playbook §5.2 rule 7 — the automation switch must be reachable "without a
 * deploy", which is why these read from the database rather than config.
 */
class PlatformController extends Controller
{
    public function index(): View
    {
        return view('superadmin.platform.index', [
            'supervisedGlobally' => PlatformSetting::supervisedGlobally(),
            // P5 — the ops surface. Everything here is a real measurement, not
            // an inference; see PlatformHealth for why each one is observable.
            'health' => app(PlatformHealth::class)->checks(),
            // Accounts supervised individually — the platform switch is separate
            // and is shown above, so this lists only deliberate per-customer calls.
            'supervisedAccounts' => Account::where('supervised', true)
                ->withCount('stores')
                ->orderBy('name')
                ->get(),
            'knobs' => [
                'Trial length' => config('billing.trial_days') . ' days',
                'Grace window' => config('billing.grace_days') . ' days',
                'Billing timezone' => config('billing.timezone'),
                'Seats per store' => config('saas.max_staff'),
                'Email verification' => config('saas.require_email_verification') ? 'required' : 'off',
                'GST registered' => config('saas.gst_registered') ? 'yes' : 'no — receipts, not tax invoices',
            ],
        ]);
    }

    /**
     * Turn supervised mode on or off for the WHOLE platform.
     *
     * ⚠ This closes self-serve checkout for every customer at once. Owner
     * constraint C1 is that self-serve must never be *degraded* — this is not
     * degradation, it is a deliberate, reversible, audited operator decision,
     * and it defaults to off.
     */
    public function supervisedMode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $before = PlatformSetting::supervisedGlobally();
        PlatformSetting::set(PlatformSetting::SUPERVISED_MODE, (bool) $validated['enabled']);

        AdminAuditLog::record(
            'platform.supervised_mode',
            $validated['enabled']
                ? 'Supervised mode ON — self-serve checkout closed platform-wide'
                : 'Supervised mode OFF — self-serve checkout open again',
            null, // platform-wide, not about one store
            [
                'reason' => $validated['reason'],
                'before' => $before,
                'after' => (bool) $validated['enabled'],
            ],
        );

        return back()->with('status', $validated['enabled']
            ? 'Supervised mode is on. Every customer now pays through you.'
            : 'Supervised mode is off. Customers can pay online again.');
    }

    /** Supervise (or release) one customer, independently of the global switch. */
    public function superviseAccount(Request $request, Account $account): RedirectResponse
    {
        $validated = $request->validate([
            'supervised' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $account->update([
            'supervised' => (bool) $validated['supervised'],
            'supervised_reason' => $validated['supervised'] ? $validated['reason'] : null,
        ]);

        AdminAuditLog::record(
            'account.supervised',
            $validated['supervised']
                ? "{$account->displayName()} is now billed by hand"
                : "{$account->displayName()} can pay online again",
            $account->stores()->value('id'),
            ['account_id' => $account->id, 'reason' => $validated['reason']],
        );

        return back()->with('status', $validated['supervised']
            ? 'They will now be billed by you.'
            : 'Self-serve checkout is open for them again.');
    }
}

<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Tenant;
use App\Services\StoreClosure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * P5 / REQ-7, matrix row 16 — closing a store and, eventually, destroying it.
 *
 * Two routes because they are two decisions weeks apart, not one action with a
 * confirmation. `close` is instant and reversible; `purge` is neither, requires
 * the store's name typed exactly, and refuses to run before the retention
 * window has elapsed. The service enforces the window — this controller cannot
 * skip it even if a future caller wanted to.
 *
 * There is deliberately no export step. Decision (08 §1): tenants are not given
 * a data-export door, so closure keeps the data for the window and then removes
 * it — the honest behaviour, and the one the public site must stop contradicting
 * (BUG-P12).
 */
class ClosureController extends Controller
{
    public function __construct(private readonly StoreClosure $closure) {}

    public function close(Request $request, Account $account, Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->account_id === $account->id, 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->closure->close($tenant, $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status',
            "{$tenant->store_name} is closed. Their data is kept until "
            . $tenant->purge_after->format('d M Y') . ', and can be restored until then.');
    }

    public function reopen(Request $request, Account $account, Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->account_id === $account->id, 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->closure->reopen($tenant, $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$tenant->store_name} is open again, with everything as it was.");
    }

    /** Irreversible. Typed confirmation, and only after the window. */
    public function purge(Request $request, Account $account, Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->account_id === $account->id, 404);

        $validated = $request->validate([
            'confirm_name' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // Typed confirmation compared exactly — a case-insensitive match on a
        // destructive action is a match you did not really make.
        if ($validated['confirm_name'] !== $tenant->store_name) {
            return back()->with('error', 'The store name did not match. Nothing was deleted.');
        }

        $name = $tenant->store_name;

        try {
            $result = $this->closure->purge($tenant, $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "{$name} and " . number_format($result['rows']) . ' rows of its data are gone.';

        if (! $result['clean']) {
            // Say so rather than report success: the incident this logic came
            // from was a deletion that looked like it had worked.
            return redirect()->route('superadmin.accounts.show', $account)
                ->with('error', $message . ' Some rows could not be removed — check the audit entry.');
        }

        return redirect()->route('superadmin.accounts.show', $account)->with('status', $message);
    }
}

<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\UnappliedPayment;
use App\Services\Settlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * E3.6 — money received online that is on no bill (FB-04, rule 4): the operator
 * says whose it is, or records that it was refunded. Applying it to a bill happens
 * on that customer's page, where it is previewed like every money action.
 */
class UnappliedController extends Controller
{
    public function __construct(private readonly Settlement $settlement) {}

    /** An unknown order's money: whose is it? (then apply it on their page) */
    public function assign(Request $request, string $unapplied): RedirectResponse
    {
        $validated = $request->validate([
            'account' => ['required', 'string', 'exists:accounts,id'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->settlement->assignUnapplied(UnappliedPayment::findOrFail($unapplied), $validated['account'], $validated['note']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->to(route('superadmin.accounts.show', $validated['account']) . '#tab-owed')
            ->with('status', 'Matched to ' . Account::find($validated['account'])?->displayName() . ' — apply it to one of their bills below, or refund it.');
    }

    /** E3-D — refunded in the Razorpay dashboard; record it here. */
    public function refunded(Request $request, string $unapplied): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:500']]);

        try {
            $this->settlement->markRefunded(UnappliedPayment::findOrFail($unapplied), $validated['note']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Recorded as refunded.');
    }
}

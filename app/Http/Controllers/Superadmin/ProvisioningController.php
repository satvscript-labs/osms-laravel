<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Plan;
use App\Services\StoreProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * P5 / REQ-2, matrix rows 1–2 — selling to someone who has never seen the site.
 *
 * This closes the last gap in "you can run the whole business by hand": until
 * now a customer had to self-signup before the panel could do anything for them,
 * which is backwards for a business whose deals are agreed face to face in a
 * shop. The operator now creates the customer, the store, the login and the
 * trial in one step, and reads the password down the phone.
 *
 * Both doors — new customer, and a branch for an existing one — post here, and
 * both go through the SAME `StoreProvisioner` that self-signup uses.
 */
class ProvisioningController extends Controller
{
    public function __construct(private readonly StoreProvisioner $provisioner) {}

    /** The form. `?account=` pre-binds it to an existing payer (a new branch). */
    public function create(Request $request): View
    {
        $account = $request->filled('account')
            ? Account::with('stores')->find($request->string('account')->toString())
            : null;

        return view('superadmin.accounts.create', [
            'account' => $account,
            'plans' => Plan::query()->orderBy('sort_order')->get(),
            'defaultTrialDays' => (int) config('billing.trial_days', 14),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'owner_name' => ['required', 'string', 'max:120'],
            // Not `unique:users` — the service checks it too, and a race between
            // the two doors must fail in the service where the transaction is.
            'owner_email' => ['required', 'email', 'max:190'],
            'store_name' => ['required', 'string', 'max:150'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'account_id' => ['nullable', 'string', Rule::exists('accounts', 'id')],
            'plan_code' => ['nullable', 'string', Rule::exists('plans', 'code')],
            // 0 is legitimate: a customer who paid on the spot should not also
            // get a fortnight free.
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'billing_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $account = isset($validated['account_id']) ? Account::find($validated['account_id']) : null;

        try {
            $result = $this->provisioner->provisionAsOperator(
                ['name' => $validated['owner_name'], 'email' => $validated['owner_email']],
                [
                    'store_name' => $validated['store_name'],
                    'tax_id' => $validated['tax_id'] ?? null,
                    'address' => $validated['address'] ?? null,
                ],
                [
                    'account' => $account,
                    'plan_code' => $validated['plan_code'] ?? null,
                    'trial_days' => $validated['trial_days'] ?? null,
                ],
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $tenant = $result['tenant'];

        // 06 §6 — the customer is the PERSON, the store is the shop. A new payer
        // is named from the owner by the provisioner; only override it when the
        // operator actually typed something different.
        if (! $account && filled($validated['customer_name'] ?? null)) {
            $tenant->account->update(['name' => $validated['customer_name']]);
        }
        if (! $account && filled($validated['billing_phone'] ?? null)) {
            $tenant->account->update(['billing_phone' => $validated['billing_phone']]);
        }

        /*
         * The password travels in a one-request flash and is rendered once. It
         * is never persisted, never logged, and cannot be shown again — if the
         * operator loses it, they re-issue, which is the correct outcome. See
         * CredentialIssuer for why the rules live in one place.
         */
        return redirect()
            ->route('superadmin.accounts.show', $tenant->account_id)
            ->with('credential', [
                'name' => $result['owner']->name,
                'email' => $result['owner']->email,
                'password' => $result['password'],
                'store' => $tenant->store_name,
            ])
            ->with('status', "{$tenant->store_name} is live. Give the owner their password now — it cannot be shown again.");
    }
}

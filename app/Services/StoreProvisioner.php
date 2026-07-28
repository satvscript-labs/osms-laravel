<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P1 / REQ-2 — ONE provisioning service, shared by every door (playbook §3.4).
 *
 * Creating a store was inline in OnboardingController; the operator door (P3),
 * seeders and imports would each have duplicated it — and "if the operator's
 * door seeds defaults the self-signup door does not, self-serve customers land
 * in a broken first run". All doors now call this.
 *
 * What one provision guarantees, atomically:
 *   account   — the paying identity (created, or an existing one for a branch)
 *   tenant    — the store, bound to its account
 *   user link — the owner's tenant_id set
 *   trial     — via the Tenant::booted() invariant, account-bound from birth
 */
class StoreProvisioner
{
    /**
     * Provision a store for an owner.
     *
     * @param User         $owner     becomes/remains the store_admin
     * @param array        $storeData store_name (required), tax_id, address, logo_url
     * @param Account|null $account   attach to an existing account (a second
     *                                branch); null = create one from the owner —
     *                                named from the PERSON, never the shop
     *                                (06 §6: "Sahaj Optical" is the shop,
     *                                "Rushi" is the customer).
     */
    public function provision(User $owner, array $storeData, ?Account $account = null): Tenant
    {
        return DB::transaction(function () use ($owner, $storeData, $account) {
            $account ??= Account::create([
                'name' => $owner->name,
                'billing_email' => $owner->email,
                'status' => 'trialing',
                'owner_user_id' => $owner->id,
            ]);

            $tenant = Tenant::create([
                'account_id' => $account->id,
                'store_name' => $storeData['store_name'],
                'tax_id' => $storeData['tax_id'] ?? null,
                'address' => $storeData['address'] ?? null,
                'logo_url' => $storeData['logo_url'] ?? null,
            ]);
            // The trial subscription is created by Tenant::booted() (ST-Enforce)
            // and inherits account_id from the tenant — no explicit creation here.

            $owner->forceFill(['tenant_id' => $tenant->id])->save();

            return $tenant;
        });
    }
}

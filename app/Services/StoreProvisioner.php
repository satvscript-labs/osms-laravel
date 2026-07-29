<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
    public function __construct(private readonly CredentialIssuer $credentials) {}

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

    /**
     * P5 / REQ-2, matrix rows 1–2 — the OPERATOR door.
     *
     * The last thing that could not be done from the panel: selling to someone
     * who has never visited the site. Before this, a customer had to self-signup
     * before you could bill them, which is backwards for a business whose deals
     * are agreed in person.
     *
     * It deliberately calls the SAME `provision()` the self-signup door calls,
     * and adds only the two things a self-signing customer does for themselves —
     * their user row and their password. The playbook's warning is exact: *"if
     * the operator's door seeds defaults the self-signup door does not,
     * self-serve customers land in a broken first run."*
     *
     * @param array $owner  name, email
     * @param array $store  store_name, tax_id, address
     * @param array $options plan_code, trial_days, account (existing Account for a branch)
     * @return array{tenant: Tenant, owner: User, password: string}
     *         The password is plaintext and exists ONLY in this return value.
     */
    public function provisionAsOperator(array $owner, array $store, array $options = []): array
    {
        $email = mb_strtolower(trim($owner['email']));

        // A duplicate email is the one failure that must not half-succeed: the
        // account and store would exist with nobody able to sign in to them.
        if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
            throw new InvalidArgumentException("Somebody already uses {$email}. Add this store to their existing customer instead.");
        }

        $password = $this->credentials->generate();

        return DB::transaction(function () use ($owner, $store, $options, $email, $password) {
            $user = User::create([
                'name' => trim($owner['name']),
                'email' => $email,
                'password' => $password,           // hashed by the model cast
                'role' => 'store_admin',
                // Operator-created accounts are verified by construction: you
                // spoke to this person. Leaving it null would hand them a
                // verification wall on a login they did not ask for.
                'email_verified_at' => now(),
            ]);

            $tenant = $this->provision($user, $store, $options['account'] ?? null);

            $this->applyTerms($tenant, $options);

            AdminAuditLog::record(
                'store.provisioned',
                "Provisioned {$tenant->store_name} for {$user->name}",
                $tenant->id,
                [
                    'account_id' => $tenant->account_id,
                    'owner_email' => $user->email,     // the address, never the secret
                    'plan_code' => $options['plan_code'] ?? null,
                    'trial_days' => $options['trial_days'] ?? null,
                    'source' => 'operator',
                ],
            );

            return ['tenant' => $tenant, 'owner' => $user, 'password' => $password];
        });
    }

    /**
     * Plan and trial length, applied to the clock this store now sits on.
     *
     * Only ever touches a subscription this provision just created. A second
     * branch joins its payer's EXISTING clock (Tenant::booted()), and silently
     * resetting a paying customer's renewal date because you added a shop is
     * precisely the drift the account layer exists to prevent.
     */
    private function applyTerms(Tenant $tenant, array $options): void
    {
        // `subscription()` is keyed on THIS tenant, so a second branch — which
        // joined its payer's existing clock rather than minting one — resolves
        // to null here and is left alone. That null IS the guard.
        //
        // withoutGlobalScopes per AUD-02: Subscription is tenant-scoped, and this
        // runs for a superadmin whose own tenant_id is null.
        $subscription = $tenant->subscription()->withoutGlobalScopes()->first();

        if (! $subscription) {
            return;
        }

        $changes = [];

        if (! empty($options['plan_code'])) {
            $plan = Plan::query()->where('code', $options['plan_code'])->first();
            if ($plan) {
                $changes['plan_id'] = $plan->id;
                $changes['tier'] = $plan->code;
            }
        }

        if (isset($options['trial_days'])) {
            $days = (int) $options['trial_days'];
            $tz = config('billing.timezone', 'Asia/Kolkata');

            // Zero trial days is a real choice — a customer who paid on the spot
            // should not also get a fortnight free. It is not "unset".
            $changes['current_period_end'] = now($tz)->addDays($days);
        }

        if ($changes !== []) {
            $subscription->forceFill($changes)->save();
        }
    }
}

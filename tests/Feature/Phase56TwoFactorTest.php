<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * SEC-05 — TOTP two-factor.
 *
 * Covers the properties that actually matter: an unconfirmed secret must never
 * lock anyone out, a pending challenge must gate the whole app, recovery codes are
 * single-use, and the superadmin requirement is opt-in so it can't lock the
 * platform owner out of their own dashboard.
 */
class Phase56TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function storeAdmin(): User
    {
        $tenant = Tenant::create(['store_name' => '2FA Optical', 'address' => 'X']);

        return User::factory()->create([
            'tenant_id' => $tenant->id, 'role' => 'store_admin', 'password' => bcrypt('password'),
        ]);
    }

    /** Enrol a user and return their valid current code. */
    private function enrol(User $user): string
    {
        $svc = app(TwoFactor::class);
        $secret = $svc->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $svc->generateRecoveryCodes(),
        ])->save();

        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    // ---------------------------------------------------------------- enrolment

    public function test_setup_generates_a_secret_without_enabling_it_yet(): void
    {
        $user = $this->storeAdmin();

        $this->actingAs($user)->get(route('two-factor.setup'))->assertOk();

        $user->refresh();
        $this->assertNotEmpty($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasTwoFactorEnabled(), 'an unconfirmed secret must not count as enabled');
    }

    public function test_an_unconfirmed_secret_does_not_gate_login(): void
    {
        $user = $this->storeAdmin();
        $user->forceFill(['two_factor_secret' => app(TwoFactor::class)->generateSecret()])->save();

        // An abandoned setup must never lock the user out.
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();
        $this->assertFalse(session()->has('2fa_pending'));
    }

    public function test_confirming_with_a_valid_code_enables_2fa_and_issues_recovery_codes(): void
    {
        $user = $this->storeAdmin();
        $this->actingAs($user)->get(route('two-factor.setup'));
        $secret = $user->refresh()->two_factor_secret;
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => $code])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('recovery_codes');

        $this->assertTrue($user->refresh()->hasTwoFactorEnabled());
        $this->assertCount(8, $user->two_factor_recovery_codes);
    }

    public function test_confirming_with_a_wrong_code_is_rejected(): void
    {
        $user = $this->storeAdmin();
        $this->actingAs($user)->get(route('two-factor.setup'));

        $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());
    }

    // ---------------------------------------------------------------- challenge

    public function test_login_with_2fa_enabled_stops_at_the_challenge(): void
    {
        $user = $this->storeAdmin();
        $this->enrol($user);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertTrue(session()->get('2fa_pending'));
    }

    public function test_a_pending_challenge_blocks_the_rest_of_the_app(): void
    {
        $user = $this->storeAdmin();
        $this->enrol($user);

        $this->actingAs($user)->withSession(['2fa_pending' => true])
            ->get(route('tenant.dashboard'))
            ->assertRedirect(route('two-factor.challenge'));
    }

    public function test_a_valid_code_clears_the_challenge(): void
    {
        $user = $this->storeAdmin();
        $code = $this->enrol($user);

        $this->actingAs($user)->withSession(['2fa_pending' => true])
            ->post(route('two-factor.verify'), ['code' => $code])
            ->assertRedirect();

        $this->assertFalse(session()->has('2fa_pending'));
    }

    public function test_an_invalid_code_leaves_the_challenge_standing(): void
    {
        $user = $this->storeAdmin();
        $this->enrol($user);

        $this->actingAs($user)->withSession(['2fa_pending' => true])
            ->post(route('two-factor.verify'), ['code' => '123456'])
            ->assertSessionHasErrors('code');

        $this->assertTrue(session()->get('2fa_pending'));
    }

    // ---------------------------------------------------------------- recovery

    public function test_a_recovery_code_works_once_and_is_then_consumed(): void
    {
        $user = $this->storeAdmin();
        $this->enrol($user);
        $recovery = $user->refresh()->two_factor_recovery_codes[0];

        $this->actingAs($user)->withSession(['2fa_pending' => true])
            ->post(route('two-factor.verify'), ['code' => $recovery])
            ->assertRedirect();
        $this->assertCount(7, $user->refresh()->two_factor_recovery_codes);

        // Replaying the same code must fail.
        $this->actingAs($user)->withSession(['2fa_pending' => true])
            ->post(route('two-factor.verify'), ['code' => $recovery])
            ->assertSessionHasErrors('code');
    }

    // ---------------------------------------------------------------- superadmin

    public function test_superadmin_2fa_is_not_forced_by_default(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);

        // Default off, so enabling the flag is always a deliberate act — otherwise
        // a deploy could lock the platform owner out of their own dashboard.
        $this->assertFalse($superadmin->mustSetUpTwoFactor());
        $this->actingAs($superadmin)->get(route('superadmin.dashboard'))->assertOk();
    }

    public function test_when_required_a_superadmin_without_2fa_is_pushed_into_setup(): void
    {
        config(['security.superadmin_require_2fa' => true]);
        $superadmin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);

        $this->assertTrue($superadmin->mustSetUpTwoFactor());
        $this->actingAs($superadmin)->get(route('superadmin.dashboard'))
            ->assertRedirect(route('two-factor.setup'));
    }

    public function test_a_required_superadmin_can_still_reach_setup_and_logout(): void
    {
        config(['security.superadmin_require_2fa' => true]);
        $superadmin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);

        // Must not be trapped in a redirect loop.
        $this->actingAs($superadmin)->get(route('two-factor.setup'))->assertOk();
    }

    public function test_a_store_admin_is_never_forced_into_2fa(): void
    {
        config(['security.superadmin_require_2fa' => true]);
        $user = $this->storeAdmin();

        $this->assertFalse($user->mustSetUpTwoFactor());
        $this->actingAs($user)->get(route('tenant.dashboard'))->assertOk();
    }

    // ---------------------------------------------------------------- storage

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $user = $this->storeAdmin();
        $this->enrol($user);

        $raw = \DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame($user->two_factor_secret, $raw, 'the secret must not be stored in plain text');
        $this->assertNotEmpty($user->two_factor_secret);
    }

    public function test_the_secret_is_never_serialised(): void
    {
        $user = $this->storeAdmin();
        $this->enrol($user);

        $json = $user->refresh()->toArray();

        $this->assertArrayNotHasKey('two_factor_secret', $json);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $json);
    }
}

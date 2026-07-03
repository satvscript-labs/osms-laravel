<?php

namespace Tests\Feature;

use App\Mail\TrialStatusMail;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Bunch 2 — ST-Legal (public compliance pages) + ST-Email (verification gate,
 * trial lifecycle mail).
 */
class Phase23SaaSCommsTest extends TestCase
{
    use RefreshDatabase;

    // ---- ST-Legal --------------------------------------------------------

    public function test_legal_pages_render(): void
    {
        foreach (['legal.terms', 'legal.privacy', 'legal.refund', 'legal.contact'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_landing_page_links_to_legal(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertSee(route('legal.terms'))
            ->assertSee(route('legal.privacy'))
            ->assertSee(route('legal.refund'))
            ->assertSee(route('legal.contact'));
    }

    // ---- ST-Email: verification gate -------------------------------------

    private function activeStore(bool $verified = true): array
    {
        $tenant = Tenant::create(['store_name' => 'Comms Optical']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'store_admin',
            'email_verified_at' => $verified ? now() : null,
        ]);

        return [$tenant, $user];
    }

    public function test_unverified_user_allowed_when_gate_is_off(): void
    {
        config(['saas.require_email_verification' => false]);
        [, $user] = $this->activeStore(verified: false);

        $this->actingAs($user)->get(route('tenant.dashboard'))->assertOk();
    }

    public function test_unverified_user_blocked_when_gate_is_on(): void
    {
        config(['saas.require_email_verification' => true]);
        [, $user] = $this->activeStore(verified: false);

        $this->actingAs($user)->get(route('tenant.dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_verified_user_allowed_when_gate_is_on(): void
    {
        config(['saas.require_email_verification' => true]);
        [, $user] = $this->activeStore(verified: true);

        $this->actingAs($user)->get(route('tenant.dashboard'))->assertOk();
    }

    // ---- ST-Email: trial lifecycle mail ----------------------------------

    private function trialStore(int $daysFromNow): Tenant
    {
        $tenant = Tenant::create(['store_name' => 'Trial Optical']);
        User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);

        // Dates are measured in the billing timezone — create them there too.
        Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->update([
                'status' => 'trialing',
                'current_period_end' => now(config('billing.timezone'))->addDays($daysFromNow),
            ]);

        return $tenant;
    }

    public function test_reconcile_sends_reminder_three_days_before_end(): void
    {
        Mail::fake();
        $this->trialStore(3);

        $this->artisan('subscriptions:reconcile')->assertSuccessful();

        Mail::assertQueued(TrialStatusMail::class, fn ($m) => $m->daysLeft === 3);
    }

    public function test_reconcile_does_not_remind_outside_thresholds(): void
    {
        Mail::fake();
        $this->trialStore(6); // not a reminder day

        $this->artisan('subscriptions:reconcile')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_reconcile_emails_and_cancels_on_expiry(): void
    {
        Mail::fake();
        $tenant = $this->trialStore(-2); // already past end

        $this->artisan('subscriptions:reconcile')->assertSuccessful();

        Mail::assertQueued(TrialStatusMail::class, fn ($m) => $m->daysLeft === 0);
        $sub = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('canceled', $sub->status);
    }
}

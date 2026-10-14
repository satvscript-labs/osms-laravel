<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\BillingReminder;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * E4.1 — the clock's foundations (16_E4_PLAN.md §3, E4-C, E4-D, E4-I).
 */
class Phase88E4FoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
        $this->actingAs($this->operator);
    }

    private function op(): self
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_a_reminder_stage_can_be_logged_once_per_cycle(): void
    {
        $this->assertTrue(Schema::hasTable('billing_reminders'));
        $account = Account::create(['name' => 'Once']);
        $row = ['account_id' => $account->id, 'cycle_end' => '2026-11-01', 'stage' => 'due_7', 'sent_at' => now()];

        BillingReminder::create($row);
        BillingReminder::create(['stage' => 'due_1'] + $row);                 // another stage: fine
        BillingReminder::create(['cycle_end' => '2026-12-01'] + $row);        // another cycle: fine

        $this->expectException(QueryException::class);
        BillingReminder::create($row);                                        // the same stage again: never
    }

    public function test_reminder_emails_start_off_and_switching_needs_a_reason_and_is_audited(): void
    {
        $this->assertFalse(PlatformSetting::renewalRemindersOn());
        $this->op()->get(route('superadmin.platform.index'))->assertOk()->assertSee('No renewal emails go to customers');

        $this->op()->patch(route('superadmin.platform.renewal-reminders'), ['enabled' => 1])->assertSessionHasErrors('reason');
        $this->assertFalse(PlatformSetting::renewalRemindersOn());

        $this->op()->patch(route('superadmin.platform.renewal-reminders'), ['enabled' => 1, 'reason' => 'read the sample'])
            ->assertSessionHas('status');
        $this->assertTrue(PlatformSetting::renewalRemindersOn());
        $log = AdminAuditLog::where('action', 'platform.renewal_reminders')->sole();
        $this->assertSame('read the sample', $log->meta['reason']);
    }

    public function test_switching_reminders_asks_for_the_password(): void
    {
        $this->patch(route('superadmin.platform.renewal-reminders'), ['enabled' => 1, 'reason' => 'x'])
            ->assertRedirect(route('password.confirm'));
        $this->assertFalse(PlatformSetting::renewalRemindersOn());
    }

    public function test_the_operator_can_put_a_billing_contact_on_file(): void
    {
        $account = Account::create(['name' => 'Contact Optical']);

        $this->op()->get(route('superadmin.accounts.show', $account))->assertOk()->assertSee('Add billing contact');

        $this->op()->patch(route('superadmin.accounts.contact', $account), ['billing_email' => 'not-an-email', 'billing_phone' => ''])
            ->assertSessionHasErrors('billing_email');
        $this->op()->patch(route('superadmin.accounts.contact', $account), ['billing_email' => '', 'billing_phone' => '12'])
            ->assertSessionHasErrors('billing_phone');

        $this->op()->patch(route('superadmin.accounts.contact', $account), ['billing_email' => 'pay@contact.in', 'billing_phone' => '98765 43210'])
            ->assertSessionHas('status', 'Billing contact saved.');

        $fresh = $account->fresh();
        $this->assertSame(['pay@contact.in', '98765 43210'], [$fresh->billing_email, $fresh->billing_phone]);
        $log = AdminAuditLog::where('action', 'account.contact_updated')->sole();
        $this->assertSame(['billing_email' => null, 'billing_phone' => null], $log->meta['before']);
        $this->assertSame('98765 43210', $log->meta['after']['billing_phone']);

        // Saving the same values again changes nothing and logs nothing.
        $this->op()->patch(route('superadmin.accounts.contact', $account), ['billing_email' => 'pay@contact.in', 'billing_phone' => '98765 43210'])
            ->assertSessionHas('status', 'Nothing changed.');
        $this->assertSame(1, AdminAuditLog::where('action', 'account.contact_updated')->count());
        $this->op()->get(route('superadmin.accounts.show', $account))->assertSee('Edit contact');
    }

    public function test_editing_a_contact_asks_for_the_password_and_store_users_cannot(): void
    {
        $account = Account::create(['name' => 'Guarded']);

        $this->patch(route('superadmin.accounts.contact', $account), ['billing_email' => 'x@y.in'])->assertRedirect(route('password.confirm'));

        $this->actingAs(User::factory()->create(['role' => 'store_admin']))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('superadmin.accounts.contact', $account), ['billing_email' => 'x@y.in'])
            ->assertForbidden();

        $this->assertNull($account->fresh()->billing_email);
    }
}

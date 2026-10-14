<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BillingCharge;
use App\Models\UnappliedPayment;
use App\Services\BillingService;
use App\Services\MoneyWrite;
use App\Services\MoneyWriteRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeRazorpay;
use Tests\TestCase;

/**
 * E3.1 — the online lane's foundations (14_E3_PLAN.md §3).
 *
 * Unapplied money is a money row like any other: written only by the engine,
 * never deleted. The checkout signature is verified with Razorpay's scheme.
 */
class Phase86E3FoundationTest extends TestCase
{
    use RefreshDatabase;

    private function fromOutsideTests(string $body, array $vars): mixed
    {
        $file = tempnam(sys_get_temp_dir(), 'e3guard') . '.php';
        file_put_contents($file, "<?php\nreturn function (array \$v) {\nextract(\$v);\n{$body}\n};\n");

        try {
            return (require $file)($vars);
        } finally {
            @unlink($file);
        }
    }

    public function test_the_schema_is_additive(): void
    {
        $this->assertTrue(Schema::hasTable('unapplied_payments'));
        $this->assertTrue(Schema::hasColumn('billing_charges', 'gateway_checkout_at'));
    }

    public function test_unapplied_money_is_written_only_by_the_engine_and_never_deleted(): void
    {
        $row = MoneyWrite::during(fn () => UnappliedPayment::create([
            'gateway_payment_id' => 'pay_X1', 'amount' => 499, 'reason' => 'test', 'status' => 'open',
        ]));

        foreach ([
            'create' => "\\App\\Models\\UnappliedPayment::create(['gateway_payment_id' => 'pay_X2', 'amount' => 1, 'reason' => 'x']);",
            'update' => "\$row->status = 'applied'; \$row->save();",
            'delete' => "\$row->delete();",
        ] as $what => $code) {
            try {
                $this->fromOutsideTests($code, ['row' => $row->fresh()]);
                $this->fail("An unapplied-payment {$what} from outside the engine must be refused.");
            } catch (MoneyWriteRefused $e) {
                $this->assertStringContainsString('UnappliedPayment', $e->getMessage());
            }
        }

        try {
            $this->fromOutsideTests("\\App\\Services\\MoneyWrite::during(fn () => \$row->delete());", ['row' => $row->fresh()]);
            $this->fail('never deleted, even by the engine');
        } catch (MoneyWriteRefused $e) {
            $this->assertStringContainsString('never deleted', $e->getMessage());
        }

        $this->assertSame(1, UnappliedPayment::count());
        $this->assertSame('open', $row->fresh()->status);
    }

    public function test_the_checkout_signature_is_razorpays_scheme_and_nothing_else(): void
    {
        config(['services.razorpay.secret' => FakeRazorpay::SECRET]);
        $gateway = new BillingService();
        $good = FakeRazorpay::callback('order_A', 'pay_B');

        $this->assertTrue($gateway->verifyPaymentSignature('order_A', 'pay_B', $good['razorpay_signature']));
        $this->assertFalse($gateway->verifyPaymentSignature('order_A', 'pay_C', $good['razorpay_signature']), 'another payment');
        $this->assertFalse($gateway->verifyPaymentSignature('order_Z', 'pay_B', $good['razorpay_signature']), 'another order');
        $this->assertFalse($gateway->verifyPaymentSignature('order_A', 'pay_B', ''), 'no signature');

        config(['services.razorpay.secret' => null]);
        $this->assertFalse($gateway->verifyPaymentSignature('order_A', 'pay_B', $good['razorpay_signature']), 'no secret, no trust');
    }

    public function test_a_bill_counts_as_being_paid_online_only_inside_the_window(): void
    {
        $account = Account::create(['name' => 'Window']);
        $bill = MoneyWrite::during(fn () => BillingCharge::create([
            'account_id' => $account->id, 'number' => 'CHG-W-1', 'kind' => 'renewal',
            'amount' => 499, 'raised_via' => 'owner', 'gateway_checkout_at' => now()->subMinutes(14),
        ]));

        $this->assertTrue($bill->isBeingPaidOnline());

        MoneyWrite::during(fn () => $bill->forceFill(['gateway_checkout_at' => now()->subMinutes(16)])->save());
        $this->assertFalse($bill->fresh()->isBeingPaidOnline());
    }
}

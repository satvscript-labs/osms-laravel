<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\AdminAuditLog;
use App\Models\Customer;
use App\Models\EyeRecord;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StaffInvitation;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\TaxInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppMessage;
use App\Services\SkuService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A single, comprehensive demo store ("Satv") that exercises EVERY table and a
 * broad spread of each field's values — for a live demo or a local sandbox.
 *
 * Covered: tenant + paid subscription + subscription invoices; store-admin owner,
 * a staff member, and a pending invitation; inventory across all four item types
 * (incl. low-stock and out-of-stock); customers spanning consent/opt-in states,
 * genders, an upcoming birthday and a minor; eye records with a second checker;
 * orders in every status and both fulfilment types, with %/₹/no discounts, an
 * overdue pickup, custom line items, and issued GST tax invoices; payments in all
 * methods; stock movements of every type; WhatsApp config + messages in several
 * delivery states; an activity log, an admin audit log, and a webhook event.
 *
 *   php artisan db:seed --class=Database\\Seeders\\ProdDemoSeeder
 *
 * Idempotent-ish: if the Satv store already exists it skips, so re-running won't
 * pile up duplicates.
 */
class ProdDemoSeeder extends Seeder
{
    private SkuService $sku;

    public function run(): void
    {
        if (Tenant::where('store_name', 'Satv')->exists()) {
            $this->command?->warn('ProdDemoSeeder skipped — a "Satv" store already exists.');

            return;
        }

        // Wrapped in a transaction: a failure partway through (e.g. a leftover
        // orphaned user from a prior deletion, a MariaDB-only type mismatch) must
        // roll back completely, never leave a broken half-seeded "Satv" tenant
        // that then silently blocks every future re-run's exists() guard above.
        DB::transaction(function () {
            $this->sku = new SkuService();

            $tenant = $this->tenant();
            [$owner, $staff] = $this->users($tenant);

            // Act as the owner so the tenant global scope auto-stamps tenant_id.
            Auth::login($owner);

            $items = $this->inventory();
            $customers = $this->customers();
            $records = $this->eyeRecords($customers, $owner, $staff);
            $this->orders($customers, $records, $items, $owner);
            $this->whatsapp($tenant, $customers);
            $this->activity($owner, $staff, $customers);

            Auth::logout();

            $this->platformLevel($tenant, $owner);
        });

        $this->command?->info('ProdDemoSeeder complete — "Satv" store fully populated.');
    }

    // ---------------------------------------------------------------- tenant

    private function tenant(): Tenant
    {
        $tenant = Tenant::create([
            'store_name' => 'Satv',
            'tax_id' => '27ABCDE1234F1Z5',       // valid 15-char GSTIN shape
            'address' => '2nd Floor, Optical Plaza, FC Road, Pune 411004',
            'internal_notes' => 'Flagship demo store. Handle with care.',
            'gst_enabled' => true,
            'gst_rate' => 12,
        ]);

        // The Tenant `created` hook makes a trialing subscription; promote it to a
        // genuinely paid, active plan so MRR/analytics have real revenue to show.
        Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->update([
                'status' => 'active',
                'tier' => 'basic',
                'interval' => 'monthly',
                'manual' => false,               // genuinely paid → counts toward MRR
                'razorpay_subscription_id' => 'sub_DEMO0000000001',
                'razorpay_customer_id' => 'cust_DEMO0000000001',
                'current_period_end' => now()->addDays(24),
                'cancel_at_period_end' => false,
            ]);

        // Three months of paid invoices (Razorpay reconciliation history).
        foreach ([3, 2, 1] as $monthsAgo) {
            $tenant->subscriptionInvoices()->create([
                'razorpay_payment_id' => 'pay_DEMO' . Str::upper(Str::random(12)),
                'razorpay_invoice_id' => 'inv_DEMO' . Str::upper(Str::random(12)),
                'razorpay_subscription_id' => 'sub_DEMO0000000001',
                'amount' => 499,
                'currency' => 'INR',
                'status' => 'paid',
                'paid_at' => now()->subMonths($monthsAgo),
            ]);
        }

        return $tenant;
    }

    // ---------------------------------------------------------------- users

    /** @return array{0: User, 1: User} owner and staff */
    private function users(Tenant $tenant): array
    {
        $owner = User::create([
            'name' => 'Satva Dev',
            'email' => 'developer@satvscript.com',
            'password' => Hash::make('password'),
            'role' => 'store_admin',
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);

        $staff = User::create([
            'name' => 'Meera Counter',
            'email' => 'meera@satvscript.com',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);

        // One pending invitation (not yet accepted) and one already accepted.
        StaffInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => 'newhire@satvscript.com',
            'role' => 'staff',
            'token' => Str::random(48),
            'expires_at' => now()->addDays((int) config('saas.invite_days', 7)),
            'accepted_at' => null,
        ]);
        StaffInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => 'meera@satvscript.com',
            'role' => 'staff',
            'token' => Str::random(48),
            'expires_at' => now()->subDays(2),
            'accepted_at' => now()->subDays(5),
        ]);

        return [$owner, $staff];
    }

    // ---------------------------------------------------------------- inventory

    /** @return \Illuminate\Support\Collection<int,Inventory> */
    private function inventory()
    {
        // [type, brand, model, cost, sell, stock, min_alert]
        $rows = [
            ['frame', 'Ray-Ban', 'Aviator Classic', 1800, 3500, 14, 5],
            ['frame', 'Oakley', 'Holbrook', 2200, 4200, 4, 5],          // low stock
            ['frame', 'Vincent Chase', 'Retro Round', 700, 1600, 0, 3], // out of stock
            ['lens', 'Essilor', 'Crizal Prevencia', 900, 2400, 40, 8],
            ['contact_lens', 'Bausch & Lomb', 'SofLens 38 (6pk)', 400, 900, 25, 6],
            ['accessory', 'Generic', 'Microfibre Cleaning Kit', 40, 150, 60, 10],
        ];

        return collect($rows)->map(function ($r) {
            $item = Inventory::create([
                'sku' => $this->sku->generateSku($r[0], $r[1]),
                'barcode' => $this->sku->generateBarcode(),
                'item_type' => $r[0], 'brand' => $r[1], 'model_name' => $r[2],
                'cost_price' => $r[3], 'selling_price' => $r[4],
                'stock_qty' => $r[5], 'min_alert_qty' => $r[6],
            ]);

            // Opening-stock movement (a 'purchase'/adjustment style entry).
            if ($r[5] > 0) {
                StockMovement::create([
                    'inventory_id' => $item->id, 'delta' => $r[5],
                    'type' => 'adjustment', 'reason' => 'Opening stock',
                    'recorded_by' => Auth::id(),
                ]);
            }

            return $item;
        });
    }

    // ---------------------------------------------------------------- customers

    /** @return \Illuminate\Support\Collection<int,Customer> */
    private function customers()
    {
        // [name, phone(nat), age, gender, birthdayOffsetDays|null, consent, optIn]
        $rows = [
            ['Rahul Kumar', '9876543210', 34, 'male', 5, true, true],     // birthday in 5 days
            ['Anjali Verma', '9988776655', 28, 'female', 200, true, false],
            ['Imran Sheikh', '9090909090', 45, 'male', null, true, true],
            ['Sneha Patil', '9812345678', 31, 'female', 3, true, true],   // birthday in 3 days
            ['Aarav Mehta', '9700011122', 15, 'male', 40, false, false],  // MINOR
            ['Fatima Khan', '9822011223', 52, 'female', null, false, false], // no consent
            ['Vikram Rao', '9765512340', 39, 'other', 90, true, false],
            ['Priya Nair', '9944553322', 26, 'female', null, true, true],
        ];

        return collect($rows)->map(function ($p) {
            $birthday = $p[4] !== null
                ? now()->addDays($p[4])->subYears($p[2])->toDateString()
                : null;

            return Customer::create([
                'name' => $p[0],
                'phone' => '+91 ' . $p[1],
                'age' => $p[2],
                'gender' => $p[3],
                'birthday' => $birthday,
                'data_consent_at' => $p[5] ? now()->subDays(random_int(1, 60)) : null,
                'whatsapp_opt_in' => $p[6],
            ]);
        });
    }

    // ---------------------------------------------------------------- eye records

    /** @return \Illuminate\Support\Collection<int,EyeRecord> */
    private function eyeRecords($customers, User $owner, User $staff)
    {
        // First four customers are "patients" with prescriptions; the rest aren't.
        return collect([
            [$customers[0], -1.50, -0.75, 90, '+1.00', -1.25, -0.50, 85, '+1.00', 62, 'Annual checkup. Stable Rx.'],
            [$customers[1], -2.25, 0, 0, null, -2.00, -0.25, 100, null, 60, 'First-time spectacles.'],
            [$customers[2], 0.75, -1.00, 15, '+1.75', 1.00, -1.25, 170, '+1.75', 64, 'Presbyopia — progressive advised.'],
            [$customers[3], -3.00, -1.50, 75, null, -3.25, -1.75, 105, null, 61, 'High myopia; recommend thin-index lens.'],
        ])->map(function ($r) use ($owner, $staff) {
            // od_nv/os_nv are decimal(6,2) — near-vision power, NOT the Snellen
            // "N6" text notation od_va uses. That string value passed SQLite (no
            // type enforcement) but crashed on MariaDB with "Incorrect decimal
            // value: 'N6'" (SQLSTATE 22007) — a real bug MySQL caught that SQLite
            // silently hid. Reuse the near-add power (already in the fixture) as a
            // domain-sensible decimal; 0.00 for the non-presbyopic rows.
            $odNv = $r[4] !== null ? (float) str_replace('+', '', $r[4]) : 0.0;
            $osNv = $r[8] !== null ? (float) str_replace('+', '', $r[8]) : 0.0;

            return EyeRecord::create([
                'customer_id' => $r[0]->id,
                'recorded_by' => $owner->id,
                'checked_by' => $staff->id,
                'od_sph' => $r[1], 'od_cyl' => $r[2], 'od_axis' => $r[3], 'od_add' => $r[4], 'od_va' => '6/6', 'od_nv' => $odNv,
                'os_sph' => $r[5], 'os_cyl' => $r[6], 'os_axis' => $r[7], 'os_add' => $r[8], 'os_va' => '6/6', 'os_nv' => $osNv,
                'pd' => $r[9], 'notes' => $r[10],
            ]);
        });
    }

    // ---------------------------------------------------------------- orders

    private function orders($customers, $records, $items, User $owner): void
    {
        // 1) DELIVERED instant sale, fully paid, cash — with a GST tax invoice.
        $o1 = $this->makeOrder($customers[0], $records[0], 'delivered', 'instant', [
            [$items[0], 1, true],   // Aviator, on tax invoice
            [$items[5], 2, true],   // cleaning kit
        ], discountType: 'percent', discountValue: 10);
        $this->pay($o1, $o1->total_amount, 'cash', $owner);
        TaxInvoice::issueFor($o1->fresh());

        // 2) READY for pickup, part-paid by UPI, special order — OVERDUE (waiting 9 days).
        $o2 = $this->makeOrder($customers[3], $records[3], 'ready_for_pickup', 'special', [
            [$items[3], 2, true],
            [$items[4], 1, false],
        ], discountType: 'amount', discountValue: 200, estimatedReadyAt: now()->subDays(2));
        $this->pay($o2, 1500, 'upi', $owner);
        $o2->forceFill(['ready_at' => now()->subDays(9)])->save(); // WEB-01 waiting clock

        // 3) PENDING special order, card advance, due to prepare today.
        $o3 = $this->makeOrder($customers[1], $records[1], 'pending', 'special', [
            [$items[1], 1, false],
        ], estimatedReadyAt: now());
        $this->pay($o3, 1000, 'card', $owner);

        // 4) CANCELLED order (stock was restored) — no payment.
        $o4 = $this->makeOrder($customers[2], $records[2], 'cancelled', 'special', [
            [$items[0], 1, false],
        ], cancelReason: 'Customer changed their mind.');

        // 5) DELIVERED with a CUSTOM (non-catalog) line item + 'other' payment.
        $o5 = $this->makeOrder($customers[7], null, 'delivered', 'instant', [
            [null, 1, true, 'Custom tinted lens (special order)', 2800],
        ]);
        $this->pay($o5, $o5->total_amount, 'other', $owner);
        TaxInvoice::issueFor($o5->fresh());
    }

    /**
     * @param  array<int,array{0:?Inventory,1:int,2:bool,3?:string,4?:float}>  $lines
     */
    private function makeOrder(
        Customer $customer,
        ?EyeRecord $record,
        string $status,
        string $fulfillment,
        array $lines,
        string $discountType = 'none',
        float $discountValue = 0,
        ?Carbon $estimatedReadyAt = null,
        ?string $cancelReason = null,
    ): Order {
        $subtotal = 0.0;
        foreach ($lines as $l) {
            $price = $l[0]?->selling_price ?? ($l[4] ?? 0);
            $subtotal += $price * $l[1];
        }

        $discountAmount = match ($discountType) {
            'percent' => round($subtotal * $discountValue / 100, 2),
            'amount' => min($discountValue, $subtotal),
            default => 0.0,
        };
        $total = $subtotal - $discountAmount;

        $order = Order::create([
            'customer_id' => $customer->id,
            'eye_record_id' => $record?->id,
            'status' => $status,
            'fulfillment_type' => $fulfillment,
            'estimated_ready_at' => $estimatedReadyAt?->toDateString(),
            'subtotal' => $subtotal,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'total_amount' => $total,
            'advance_paid' => 0,
            'cancelled_at' => $status === 'cancelled' ? now() : null,
            'cancel_reason' => $cancelReason,
        ]);

        foreach ($lines as $l) {
            $inv = $l[0];
            $order->items()->create([
                'inventory_id' => $inv?->id,
                'description' => $inv ? null : ($l[3] ?? 'Custom item'),
                'quantity' => $l[1],
                'unit_price' => $inv?->selling_price ?? ($l[4] ?? 0),
                'list_price' => $inv?->selling_price ?? ($l[4] ?? 0),
                'on_tax_invoice' => $l[2],
            ]);

            // Stock movement for the sale (skip custom lines and cancelled orders).
            if ($inv && $status !== 'cancelled') {
                StockMovement::create([
                    'inventory_id' => $inv->id, 'delta' => -$l[1],
                    'type' => 'order', 'reason' => 'Order placed',
                    'order_id' => $order->id, 'recorded_by' => Auth::id(),
                ]);
            }
        }

        // Cancelled order restores stock — record that movement too.
        if ($status === 'cancelled') {
            foreach ($lines as $l) {
                if ($l[0]) {
                    StockMovement::create([
                        'inventory_id' => $l[0]->id, 'delta' => $l[1],
                        'type' => 'cancel', 'reason' => 'Order cancelled — stock restored',
                        'order_id' => $order->id, 'recorded_by' => Auth::id(),
                    ]);
                }
            }
        }

        return $order;
    }

    private function pay(Order $order, float $amount, string $method, User $by): void
    {
        $order->payments()->create([
            'amount' => $amount, 'method' => $method,
            'note' => ucfirst($method) . ' payment', 'recorded_by' => $by->id,
        ]);
        $order->update(['advance_paid' => (float) $order->advance_paid + $amount]);
    }

    // ---------------------------------------------------------------- whatsapp

    private function whatsapp(Tenant $tenant, $customers): void
    {
        // Manual mode — the shipping product (Automated is frozen in production).
        WhatsAppConfig::create([
            'tenant_id' => $tenant->id,
            'mode' => 'manual',
            'on_placed' => true, 'on_ready' => true, 'on_delivered' => false, 'on_birthday' => true,
            'tpl_lang' => 'en',
            'enabled' => false,
            'default_country_code' => '+91',
        ]);

        // A spread of message rows in different delivery states (demo of the log UI).
        $states = [
            ['order_ready', 'sent', 'sent'],
            ['order_placed', 'delivered', 'delivered'],
            ['birthday', 'failed', null],
            ['order_ready', 'scheduled', null],
        ];
        foreach ($states as $i => [$event, $status, $delivery]) {
            WhatsAppMessage::create([
                'tenant_id' => $tenant->id,
                'customer_id' => $customers[$i]->id,
                'event' => $event,
                'channel' => 'whatsapp',
                'to_phone' => preg_replace('/\D/', '', $customers[$i]->phone),
                'template_name' => $event,
                'status' => $status,
                'scheduled_for' => now()->subMinutes(30 * ($i + 1)),
                'sent_at' => in_array($status, ['sent', 'delivered'], true) ? now()->subMinutes(28 * ($i + 1)) : null,
                'delivery_status' => $delivery,
                'error' => $status === 'failed' ? 'Recipient not on WhatsApp' : null,
                'attempts' => $status === 'failed' ? 3 : 1,
                'dedupe_key' => $status === 'scheduled' ? "demo:{$event}:{$i}" : null,
            ]);
        }
    }

    // ---------------------------------------------------------------- activity

    private function activity(User $owner, User $staff, $customers): void
    {
        ActivityLog::create([
            'user_id' => $owner->id, 'user_name' => $owner->name,
            'action' => 'customer.created',
            'subject_type' => Customer::class, 'subject_id' => $customers[0]->id,
            'description' => "Added customer {$customers[0]->name}",
            'ip_address' => '203.0.113.10',
        ]);
        ActivityLog::create([
            'user_id' => $staff->id, 'user_name' => $staff->name,
            'action' => 'order.status_changed',
            'description' => 'Moved an order to ready for pickup',
            'ip_address' => '203.0.113.11',
        ]);
    }

    // ---------------------------------------------------------------- platform level

    private function platformLevel(Tenant $tenant, User $owner): void
    {
        // A superadmin audit line (no tenant scope — platform-level table).
        AdminAuditLog::create([
            'admin_user_id' => null,
            'admin_email' => (string) env('SUPERADMIN_EMAIL', 'admin@satvscript.com'),
            'action' => 'subscription.updated',
            'tenant_id' => $tenant->id,
            'description' => "Activated {$tenant->store_name}'s paid subscription (demo seed)",
            'ip_address' => '198.51.100.5',
        ]);

        // A processed Razorpay webhook id (idempotency ledger).
        WebhookEvent::create([
            'id' => 'evt_DEMO' . Str::upper(Str::random(16)),
            'type' => 'subscription.charged',
        ]);
    }
}

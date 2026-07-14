<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\EyeRecord;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SkuService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates a dedicated QA sandbox tenant filled with a broad, realistic
 * spectrum of test data (inventory, orders, customers with upcoming birthdays,
 * low stock alerts, etc.) safely isolated from any other test accounts.
 *
 * Run with: php artisan db:seed --class=TestingEnvironmentSeeder
 */
class TestingEnvironmentSeeder extends Seeder
{
    private SkuService $sku;

    public function run(): void
    {
        $this->sku = new SkuService();

        // 1. Create Dedicated QA Tenant
        $tenant = Tenant::create([
            'store_name' => 'QA Sandbox',
            'tax_id' => '99QATST0000A1Z5',
            'address' => 'Test Environment Lane, Null Island',
        ]);

        // Upgrade to PRO tier to ensure all features are accessible
        Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->update(['tier' => 'pro']);

        // 2. Create QA Admin User
        $owner = User::create([
            'name' => 'QA Tester',
            'email' => 'qa@osms.test',
            'password' => Hash::make('password'),
            'role' => 'store_admin',
            'tenant_id' => $tenant->id,
        ]);

        // BelongsToTenant stamps tenant_id from the authenticated user.
        Auth::login($owner);

        // 3. Seed extensive demo data
        $inventory = $this->seedInventory();
        $customers = $this->seedCustomers();
        $this->seedEyeRecords($customers, $owner);
        $this->seedOrders($customers, $inventory, $owner);

        Auth::logout();

        $this->command?->info('Dedicated QA Sandbox seeded successfully.');
        $this->command?->info('Login with: qa@osms.test / password');
        $this->command?->info('Seeded: ' . $inventory->count() . ' inventory items, ' . $customers->count() . ' customers, plus orders and payments.');
    }

    /** A broad catalog spread across all four item types and every stock tier. */
    private function seedInventory()
    {
        $rows = [
            // [type, brand, model, cost, price, stock, min_alert]
            ['frame', 'Ray-Ban', 'Wayfarer Classic', 2100, 4500, 18, 5],
            ['frame', 'Vogue', 'Eyewear VO5052', 1400, 3200, 2, 5],   // low stock
            ['frame', 'Titan', 'EyePlus TR90', 900, 2200, 0, 5],     // out of stock
            ['frame', 'Fastrack', 'Full Rim Square', 700, 1600, 25, 5],
            ['frame', 'John Jacobs', 'Round Metal', 1100, 2800, 4, 5], // low stock
            ['lens', 'Essilor', 'Crizal Alizé', 550, 1400, 40, 10],
            ['lens', 'Zeiss', 'Progressive DuraVision', 2800, 6500, 8, 10], // low stock
            ['lens', 'Essilor', 'Anti-Glare Basic', 250, 700, 60, 10],
            ['contact_lens', 'Bausch & Lomb', 'SofLens Daily', 450, 1000, 22, 8],
            ['contact_lens', 'Acuvue', 'Oasys 2-Week', 900, 1900, 0, 8],  // out of stock
            ['contact_lens', 'Alcon', 'Dailies AquaComfort', 500, 1100, 15, 8],
            ['accessory', 'Generic', 'Microfiber Cleaning Cloth', 20, 80, 100, 20],
            ['accessory', 'Generic', 'Lens Cleaning Spray', 60, 180, 30, 10],
            ['accessory', 'Generic', 'Spectacle Case (Hard)', 90, 250, 3, 10], // low stock
            ['accessory', 'Generic', 'Anti-Fog Wipes (Pack of 10)', 40, 150, 50, 15],
        ];

        return collect($rows)->map(fn ($r) => Inventory::create([
            'sku' => $this->sku->generateSku($r[0], $r[1]),
            'barcode' => $this->sku->generateBarcode(),
            'item_type' => $r[0], 'brand' => $r[1], 'model_name' => $r[2],
            'cost_price' => $r[3], 'selling_price' => $r[4],
            'stock_qty' => $r[5], 'min_alert_qty' => $r[6],
        ]));
    }

    private function seedCustomers()
    {
        $firstNames = ['QA-Rahul', 'QA-Anjali', 'QA-Imran', 'QA-Priya', 'QA-Karan', 'QA-Sneha', 'QA-Arjun', 'QA-Divya',
            'QA-Vikram', 'QA-Neha', 'QA-Rohan', 'QA-Pooja', 'QA-Aditya', 'QA-Meera', 'QA-Sanjay', 'QA-Kavita',
            'QA-Amit', 'QA-Ritu', 'QA-Nikhil', 'QA-Shreya', 'QA-Manish', 'QA-Isha', 'QA-Deepak', 'QA-Tanya'];
        $lastNames = ['Sharma', 'Verma', 'Sheikh', 'Iyer', 'Gupta', 'Nair', 'Reddy', 'Patel',
            'Khanna', 'Joshi', 'Chawla', 'Menon', 'Kapoor', 'Bhatt', 'Rao', 'Malhotra'];

        $rows = [];
        $usedPhones = [];
        $phoneFor = function () use (&$usedPhones) {
            do {
                $n = '9' . random_int(100000000, 999999999);
            } while (isset($usedPhones[$n]));
            $usedPhones[$n] = true;
            return '+91 ' . $n;
        };

        $birthdayOffsets = [0, 1, 3, 6];
        foreach ($birthdayOffsets as $i => $offset) {
            $age = random_int(22, 55);
            $rows[] = [
                'name' => $firstNames[$i] . ' ' . $lastNames[$i],
                'phone' => $phoneFor(),
                'age' => $age,
                'birthday' => now()->addDays($offset)->subYears($age)->toDateString(),
                'gender' => ['male', 'female', 'other'][array_rand(['male', 'female', 'other'])],
            ];
        }

        for ($i = count($birthdayOffsets); $i < 24; $i++) {
            $age = random_int(18, 68);
            $hasBirthday = $i % 4 !== 0;
            $rows[] = [
                'name' => $firstNames[$i % count($firstNames)] . ' ' . $lastNames[($i * 3) % count($lastNames)],
                'phone' => $phoneFor(),
                'age' => $hasBirthday ? null : $age,
                'birthday' => $hasBirthday ? now()->addDays(random_int(20, 300))->subYears($age)->toDateString() : null,
                'gender' => $i % 5 === 0 ? null : ['male', 'female', 'other'][$i % 3],
            ];
        }

        return collect($rows)->map(fn ($r) => Customer::create($r));
    }

    private function seedEyeRecords($customers, $owner): void
    {
        $patients = $customers->random(8);

        foreach ($patients as $c) {
            EyeRecord::create([
                'customer_id' => $c->id,
                'recorded_by' => $owner->id,
                'checked_by' => 'Dr. QA Smith',
                'od_sph' => $this->rx(), 'od_cyl' => $this->rx(-3, 0), 'od_axis' => random_int(0, 180), 'od_va' => '6/6',
                'os_sph' => $this->rx(), 'os_cyl' => $this->rx(-3, 0), 'os_axis' => random_int(0, 180), 'os_va' => '6/6',
                'pd' => random_int(58, 66),
                'notes' => 'Routine QA checkup.',
            ]);
        }

        $returning = $patients->first();
        if ($returning) {
            EyeRecord::create([
                'customer_id' => $returning->id,
                'recorded_by' => $owner->id,
                'checked_by' => 'Dr. QA Smith',
                'od_sph' => $this->rx(), 'od_axis' => random_int(0, 180), 'od_va' => '6/9',
                'os_sph' => $this->rx(), 'os_axis' => random_int(0, 180), 'os_va' => '6/9',
                'pd' => random_int(58, 66),
                'notes' => 'Follow-up QA — mild progression.',
            ]);
        }
    }

    private function rx(float $min = -6, float $max = 6): float
    {
        return round(random_int((int) ($min * 4), (int) ($max * 4)) / 4, 2);
    }

    private function seedOrders($customers, $inventory, User $owner): void
    {
        $methods = ['cash', 'card', 'upi', 'other'];

        // [status, fulfillment_type, paidFraction, daysAgoCreated, readyOffsetDays]
        $plan = [
            ['pending', 'special', 0.4, 2, 5],
            ['pending', 'special', 0.0, 5, 3],
            ['pending', 'special', 0.5, 1, -2],   // overdue ready date
            ['ready_for_pickup', 'special', 0.5, 10, -1],
            ['ready_for_pickup', 'special', 1.0, 6, 2],
            ['ready_for_pickup', 'special', 0.3, 12, -4], // overdue
            ['delivered', 'special', 1.0, 20, null],
            ['delivered', 'special', 0.6, 15, null],      // balance due
            ['delivered', 'special', 0.7, 30, null],      // balance due
            ['delivered', 'instant', 1.0, 3, null],
            ['delivered', 'instant', 1.0, 7, null],
            ['delivered', 'instant', 0.5, 1, null],       // instant sale, balance due
            ['delivered', 'instant', 1.0, 0, null],
            ['cancelled', 'special', 0.4, 18, null],
            ['cancelled', 'instant', 0.0, 25, null],
        ];

        foreach ($plan as $i => [$status, $fulfillment, $paidFraction, $daysAgo, $readyOffset]) {
            $customer = $customers[$i % $customers->count()];
            $lines = $this->pickLines($inventory, $i);

            $subtotal = round($lines->sum(fn ($l) => $l['unit_price'] * $l['quantity']), 2);
            [$discountType, $discountValue, $discountAmount] = $this->maybeDiscount($i, $subtotal);
            $total = round($subtotal - $discountAmount, 2);
            $advance = round($total * $paidFraction, 2);

            $createdAt = now()->subDays($daysAgo);
            $readyAt = $readyOffset !== null ? now()->addDays($readyOffset)->toDateString() : null;

            $order = Order::create([
                'customer_id' => $customer->id,
                'status' => $status,
                'fulfillment_type' => $fulfillment,
                'estimated_ready_at' => $fulfillment === 'special' ? $readyAt : null,
                'subtotal' => $subtotal,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'total_amount' => $total,
                'advance_paid' => $advance,
            ]);

            if ($status === 'cancelled') {
                $order->cancelled_at = $createdAt->copy()->addDay();
                $order->cancel_reason = 'Customer changed their mind (QA)';
                $order->save();
            }

            $order->items()->createMany($lines->map(fn ($l) => [
                'inventory_id' => $l['inventory_id'],
                'description' => $l['description'],
                'quantity' => $l['quantity'],
                'unit_price' => $l['unit_price'],
                'list_price' => $l['list_price'],
            ])->all());

            foreach ($lines as $l) {
                if ($l['inventory_id'] === null) {
                    continue;
                }
                $inv = $inventory->firstWhere('id', $l['inventory_id']);
                $inv?->decrement('stock_qty', $l['quantity']);
                StockMovement::create([
                    'inventory_id' => $l['inventory_id'],
                    'delta' => -$l['quantity'],
                    'type' => $status === 'cancelled' ? 'order' : 'order',
                    'reason' => 'Order placed (QA data)',
                    'order_id' => $order->id,
                    'recorded_by' => $owner->id,
                ]);
            }
            if ($status === 'cancelled') {
                foreach ($lines as $l) {
                    if ($l['inventory_id'] === null) {
                        continue;
                    }
                    $inv = $inventory->firstWhere('id', $l['inventory_id']);
                    $inv?->increment('stock_qty', $l['quantity']);
                    StockMovement::create([
                        'inventory_id' => $l['inventory_id'],
                        'delta' => $l['quantity'],
                        'type' => 'cancel',
                        'reason' => 'Order cancelled (QA data)',
                        'order_id' => $order->id,
                        'recorded_by' => $owner->id,
                    ]);
                }
            }

            if ($advance > 0) {
                Payment::create([
                    'order_id' => $order->id,
                    'amount' => $advance,
                    'method' => $methods[$i % count($methods)],
                    'note' => 'Initial advance (QA data)',
                    'recorded_by' => $owner->id,
                ]);
            }

            DB::table('orders')->where('id', $order->id)->update([
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            DB::table('payments')->where('order_id', $order->id)->update([
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }
    }

    private function pickLines($inventory, int $seed)
    {
        $available = $inventory->filter(fn ($inv) => $inv->stock_qty > 0);
        if ($available->isEmpty()) {
            $available = $inventory; 
        }

        $catalog = $available->random(min(2, $available->count()))->values()->map(fn ($inv) => [
            'inventory_id' => $inv->id,
            'description' => null,
            'quantity' => 1,
            'unit_price' => (float) $inv->selling_price,
            'list_price' => (float) $inv->selling_price,
        ]);

        if ($seed % 4 === 0) {
            $catalog->push([
                'inventory_id' => null,
                'description' => 'Express fitting service',
                'quantity' => 1,
                'unit_price' => 150.0,
                'list_price' => 150.0,
            ]);
        }

        return $catalog;
    }

    private function maybeDiscount(int $seed, float $subtotal): array
    {
        if ($subtotal <= 0) {
            return ['none', 0.0, 0.0];
        }
        return match ($seed % 3) {
            0 => ['percent', 10.0, round($subtotal * 0.10, 2)],
            1 => ['amount', 100.0, min(100.0, $subtotal)],
            default => ['none', 0.0, 0.0],
        };
    }
}

<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\EyeRecord;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SkuService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Additive local-only demo data for the "Sahaj Optical" dev tenant (created by
 * the base DatabaseSeeder). Safe to run on top of whatever you already have —
 * it only ever creates new rows, never touches existing ones. Re-running adds
 * another batch rather than replacing anything.
 *
 * Run with: php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private SkuService $sku;

    public function run(): void
    {
        $this->sku = new SkuService();

        $tenant = Tenant::where('store_name', 'Sahaj Optical')->first();
        if (! $tenant) {
            $this->command?->warn('No "Sahaj Optical" tenant found — run the base DatabaseSeeder first.');
            return;
        }

        $owner = User::where('tenant_id', $tenant->id)->where('role', 'store_admin')->first();
        if (! $owner) {
            $this->command?->warn('No store admin found for Sahaj Optical.');
            return;
        }

        // BelongsToTenant stamps tenant_id from the authenticated user.
        Auth::login($owner);

        $inventory = $this->seedInventory();
        $customers = $this->seedCustomers();
        $this->seedEyeRecords($customers);
        $this->seedOrders($customers, $inventory, $owner);

        Auth::logout();

        $this->command?->info('Demo data seeded for Sahaj Optical: '
            . $inventory->count() . ' inventory items, '
            . $customers->count() . ' customers, orders + payments across every status.');
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

    /**
     * A mixed batch of customers: some with a birthday inside the next 7 days
     * (to populate the Birthdays tab), some further out, some with only an age,
     * some patients-to-be, some not.
     */
    private function seedCustomers()
    {
        $firstNames = ['Rahul', 'Anjali', 'Imran', 'Priya', 'Karan', 'Sneha', 'Arjun', 'Divya',
            'Vikram', 'Neha', 'Rohan', 'Pooja', 'Aditya', 'Meera', 'Sanjay', 'Kavita',
            'Amit', 'Ritu', 'Nikhil', 'Shreya', 'Manish', 'Isha', 'Deepak', 'Tanya'];
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

        // A handful of birthdays deliberately inside the next 7 days (today, +1, +3, +6)
        // so the Birthdays tab has something to show right away.
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

        // The rest: birthdays scattered well outside the 7-day window, a few with
        // no birthday at all (age-only, per the pre-birthday-field fallback), and
        // a few with neither (minimal record).
        for ($i = count($birthdayOffsets); $i < 24; $i++) {
            $age = random_int(18, 68);
            $hasBirthday = $i % 4 !== 0; // 3-in-4 have a birthday on file
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

    /** Prescriptions for roughly a third of the customers (the "patients" cohort). */
    private function seedEyeRecords($customers): void
    {
        $patients = $customers->random(8);

        foreach ($patients as $c) {
            EyeRecord::create([
                'customer_id' => $c->id,
                'recorded_by' => auth()->id(),
                'checked_by' => 'Dr. Sahaj Mehta',
                'od_sph' => $this->rx(), 'od_cyl' => $this->rx(-3, 0), 'od_axis' => random_int(0, 180), 'od_va' => '6/6',
                'os_sph' => $this->rx(), 'os_cyl' => $this->rx(-3, 0), 'os_axis' => random_int(0, 180), 'os_va' => '6/6',
                'pd' => random_int(58, 66),
                'notes' => 'Routine checkup.',
            ]);
        }

        // One returning patient with two records, to exercise the timeline.
        $returning = $patients->first();
        if ($returning) {
            EyeRecord::create([
                'customer_id' => $returning->id,
                'recorded_by' => auth()->id(),
                'checked_by' => 'Dr. Sahaj Mehta',
                'od_sph' => $this->rx(), 'od_axis' => random_int(0, 180), 'od_va' => '6/9',
                'os_sph' => $this->rx(), 'os_axis' => random_int(0, 180), 'os_va' => '6/9',
                'pd' => random_int(58, 66),
                'notes' => 'Follow-up — mild progression.',
            ]);
        }
    }

    private function rx(float $min = -6, float $max = 6): float
    {
        return round(random_int((int) ($min * 4), (int) ($max * 4)) / 4, 2);
    }

    /** Orders spread across every status, fulfillment type, and payment state. */
    private function seedOrders($customers, $inventory, User $owner): void
    {
        $methods = ['cash', 'card', 'upi', 'other'];

        // [status, fulfillment_type, paidFraction (0=nothing paid,1=paid in full), daysAgoCreated, readyOffsetDays]
        $plan = [
            ['pending', 'special', 0.4, 2, 5],
            ['pending', 'special', 0.0, 5, 3],
            ['pending', 'special', 0.5, 1, -2],   // overdue ready date
            ['ready_for_pickup', 'special', 0.5, 10, -1],
            ['ready_for_pickup', 'special', 1.0, 6, 2],
            ['ready_for_pickup', 'special', 0.3, 12, -4], // overdue
            ['delivered', 'special', 1.0, 20, null],
            ['delivered', 'special', 0.6, 15, null],      // balance due → Pending Dues
            ['delivered', 'special', 0.7, 30, null],      // balance due → Pending Dues
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
                $order->cancel_reason = 'Customer changed their mind';
                $order->save();
            }

            $order->items()->createMany($lines->map(fn ($l) => [
                'inventory_id' => $l['inventory_id'],
                'description' => $l['description'],
                'quantity' => $l['quantity'],
                'unit_price' => $l['unit_price'],
                'list_price' => $l['list_price'],
            ])->all());

            // Draw down stock + log the movement for catalog lines, mirroring the
            // real order flow so inventory numbers stay internally consistent.
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
                    'reason' => 'Order placed (demo data)',
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
                        'reason' => 'Order cancelled (demo data)',
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
                    'note' => 'Initial advance (demo data)',
                    'recorded_by' => $owner->id,
                ]);
            }

            // Backdate timestamps directly (bypassing model events) so analytics /
            // the ledger have a realistic date spread instead of everything "today".
            DB::table('orders')->where('id', $order->id)->update([
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            DB::table('payments')->where('order_id', $order->id)->update([
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }
    }

    /** 1–3 lines per order; occasionally include a local/custom (off-catalog) line. */
    private function pickLines($inventory, int $seed)
    {
        // Only draw from items that currently have stock — $inventory holds live
        // model instances that earlier orders in this same run have already
        // decremented, so this must be checked fresh each call, not precomputed.
        $available = $inventory->filter(fn ($inv) => $inv->stock_qty > 0);
        if ($available->isEmpty()) {
            $available = $inventory; // fallback: every item is out — don't crash the run
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

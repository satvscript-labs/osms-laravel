<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\EyeRecord;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SkuService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ---- Platform superadmin ----
        User::create([
            'name' => 'OSMS Admin',
            'email' => 'admin@osms.test',
            'password' => Hash::make('password'),
            'role' => 'superadmin',
        ]);

        // ---- Demo store ----
        $tenant = Tenant::create([
            'store_name' => 'Sahaj Optical',
            'tax_id' => '22AAAAA0000A1Z5',
            'address' => '123 Market Road, Mumbai',
        ]);

        // The trial subscription is auto-created by the Tenant `created` hook
        // (ST-Enforce); bump the demo store to the 'pro' tier for a fuller demo.
        Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->update(['tier' => 'pro']);

        $owner = User::create([
            'name' => 'Riya Shah',
            'email' => 'owner@sahajoptical.test',
            'password' => Hash::make('password'),
            'role' => 'store_admin',
            'tenant_id' => $tenant->id,
        ]);

        // Act as the owner so the tenant global scope stamps tenant_id automatically.
        Auth::login($owner);

        $sku = new SkuService();
        $inventory = collect([
            ['frame', 'Ray-Ban', 'Aviator Classic', 1800, 3500, 12],
            ['frame', 'Oakley', 'Holbrook', 2200, 4200, 6],
            ['lens', 'Essilor', 'Crizal UV', 600, 1500, 30],
            ['contact_lens', 'Bausch & Lomb', 'SofLens 38', 400, 900, 20],
            ['accessory', 'Generic', 'Cleaning Kit', 50, 150, 40],
        ])->map(fn ($r) => Inventory::create([
            'sku' => $sku->generateSku($r[0], $r[1]),
            'barcode' => $sku->generateBarcode(),
            'item_type' => $r[0], 'brand' => $r[1], 'model_name' => $r[2],
            'cost_price' => $r[3], 'selling_price' => $r[4],
            'stock_qty' => $r[5], 'min_alert_qty' => 5,
        ]));

        $customers = collect([
            ['Rahul Kumar', '9876543210', 34, 'male'],
            ['Anjali Verma', '9988776655', 28, 'female'],
            ['Imran Sheikh', '9090909090', 45, 'male'],
        ])->map(fn ($p) => Customer::create([
            'name' => $p[0], 'phone' => $p[1], 'age' => $p[2], 'gender' => $p[3],
        ]));

        // Eye record + order for the first customer
        $rx = EyeRecord::create([
            'customer_id' => $customers[0]->id, 'recorded_by' => $owner->id,
            'od_sph' => -1.50, 'od_cyl' => -0.75, 'od_axis' => 90, 'od_va' => '6/6',
            'os_sph' => -1.25, 'os_cyl' => -0.50, 'os_axis' => 85, 'os_va' => '6/6',
            'pd' => 62, 'notes' => 'Annual checkup. Stable Rx.',
        ]);

        $order = Order::create([
            'customer_id' => $customers[0]->id, 'eye_record_id' => $rx->id,
            'status' => 'ready_for_pickup',
            'total_amount' => $inventory[0]->selling_price + $inventory[2]->selling_price,
            'advance_paid' => 2000,
        ]);
        $order->items()->createMany([
            ['inventory_id' => $inventory[0]->id, 'quantity' => 1, 'unit_price' => $inventory[0]->selling_price],
            ['inventory_id' => $inventory[2]->id, 'quantity' => 1, 'unit_price' => $inventory[2]->selling_price],
        ]);

        // A delivered order so analytics has data
        $delivered = Order::create([
            'customer_id' => $customers[1]->id, 'status' => 'delivered',
            'total_amount' => $inventory[1]->selling_price, 'advance_paid' => $inventory[1]->selling_price,
        ]);
        $delivered->items()->create([
            'inventory_id' => $inventory[1]->id, 'quantity' => 1, 'unit_price' => $inventory[1]->selling_price,
        ]);

        Auth::logout();
    }
}

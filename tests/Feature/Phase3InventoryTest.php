<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SkuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase3InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function storeUser(): User
    {
        $tenant = Tenant::create(['store_name' => 'Test Optical']);
        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
    }

    public function test_sku_and_barcode_formats(): void
    {
        $sku = new SkuService();
        $this->assertMatchesRegularExpression('/^FRM-[A-Z0-9]{1,5}-[A-Z0-9]{6}$/', $sku->generateSku('frame', 'Oakley'));
        $this->assertMatchesRegularExpression('/^LNS-GEN-[A-Z0-9]{6}$/', $sku->generateSku('lens', null));
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{11}$/', $sku->generateBarcode());
    }

    public function test_inventory_pages_render(): void
    {
        $user = $this->storeUser();
        $this->actingAs($user)->get(route('tenant.inventory.index'))->assertOk();
        $this->actingAs($user)->get(route('tenant.inventory.create'))->assertOk();
    }

    public function test_create_item_auto_generates_sku_and_barcode(): void
    {
        $user = $this->storeUser();

        $this->actingAs($user)->post(route('tenant.inventory.store'), [
            'item_type' => 'frame',
            'brand' => 'Ray-Ban',
            'model_name' => 'Aviator',
            'cost_price' => 1000,
            'selling_price' => 2500,
            'is_tracked' => 1,
            'stock_qty' => 10,
            'min_alert_qty' => 3,
        ])->assertRedirect(route('tenant.inventory.index'));

        $item = Inventory::withoutGlobalScopes()->first();
        $this->assertNotNull($item);
        $this->assertStringStartsWith('FRM-', $item->sku);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{11}$/', $item->barcode);
        $this->assertSame($user->tenant_id, $item->tenant_id);
    }

    public function test_scan_finds_item_by_barcode_and_sku(): void
    {
        $user = $this->storeUser();
        $this->actingAs($user);
        $item = Inventory::create([
            'sku' => 'FRM-RAYBA-ABC123', 'barcode' => '123456789012', 'item_type' => 'frame',
            'brand' => 'Ray-Ban', 'model_name' => 'Aviator', 'cost_price' => 1000,
            'selling_price' => 2500, 'stock_qty' => 5, 'min_alert_qty' => 2,
        ]);

        $this->actingAs($user)->getJson(route('tenant.inventory.scan', ['q' => '123456789012']))
            ->assertOk()->assertJson(['found' => true, 'item' => ['sku' => 'FRM-RAYBA-ABC123']]);

        $this->actingAs($user)->getJson(route('tenant.inventory.scan', ['q' => 'FRM-RAYBA-ABC123']))
            ->assertOk()->assertJson(['found' => true]);

        $this->actingAs($user)->getJson(route('tenant.inventory.scan', ['q' => 'NOPE']))
            ->assertOk()->assertJson(['found' => false]);
    }

    public function test_scan_respects_tenant_isolation(): void
    {
        $u1 = $this->storeUser();
        $this->actingAs($u1);
        Inventory::create([
            'sku' => 'FRM-X-AAA111', 'barcode' => '999999999999', 'item_type' => 'frame',
            'brand' => 'X', 'cost_price' => 1, 'selling_price' => 2, 'stock_qty' => 1, 'min_alert_qty' => 1,
        ]);

        $u2 = $this->storeUser();
        $this->actingAs($u2)->getJson(route('tenant.inventory.scan', ['q' => '999999999999']))
            ->assertOk()->assertJson(['found' => false]);
    }

    public function test_low_stock_filter(): void
    {
        $user = $this->storeUser();
        $this->actingAs($user);
        Inventory::create(['sku' => 'A', 'barcode' => '1', 'item_type' => 'frame', 'brand' => 'Low',
            'cost_price' => 1, 'selling_price' => 2, 'stock_qty' => 1, 'min_alert_qty' => 5]);
        Inventory::create(['sku' => 'B', 'barcode' => '2', 'item_type' => 'frame', 'brand' => 'Healthy',
            'cost_price' => 1, 'selling_price' => 2, 'stock_qty' => 50, 'min_alert_qty' => 5]);

        $this->actingAs($user)->get(route('tenant.inventory.index', ['stock' => 'low']))
            ->assertOk()->assertSee('Low')->assertDontSee('Healthy');
    }

    public function test_inventory_index_is_paginated(): void
    {
        $user = $this->storeUser();
        $this->actingAs($user);
        for ($i = 0; $i < 55; $i++) {
            Inventory::create([
                'sku' => 'SKU-' . $i, 'barcode' => (string) (100000000000 + $i), 'item_type' => 'frame',
                'brand' => 'Brand', 'model_name' => 'M' . $i, 'cost_price' => 1,
                'selling_price' => 2, 'stock_qty' => 5, 'min_alert_qty' => 1,
            ]);
        }

        $page1 = $this->actingAs($user)->get(route('tenant.inventory.index'));
        $page1->assertOk();
        $this->assertCount(50, $page1->viewData('items'));
        $this->assertSame(55, $page1->viewData('items')->total());

        $this->actingAs($user)->get(route('tenant.inventory.index', ['page' => 2]))
            ->assertOk()->assertViewHas('items', fn ($p) => $p->count() === 5);
    }

    public function test_update_item(): void
    {
        $user = $this->storeUser();
        $this->actingAs($user);
        $item = Inventory::create(['sku' => 'A', 'barcode' => '1', 'item_type' => 'frame', 'brand' => 'Old',
            'cost_price' => 1, 'selling_price' => 2, 'stock_qty' => 1, 'min_alert_qty' => 5]);

        $this->actingAs($user)->put(route('tenant.inventory.update', $item), [
            'item_type' => 'frame', 'brand' => 'New', 'model_name' => 'M',
            'cost_price' => 100, 'selling_price' => 200, 'is_tracked' => 1, 'stock_qty' => 20, 'min_alert_qty' => 4,
        ])->assertRedirect(route('tenant.inventory.index'));

        $this->assertDatabaseHas('inventory', ['id' => $item->id, 'brand' => 'New', 'stock_qty' => 20]);
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryRequest;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Services\SkuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');
        $stock = (string) $request->query('stock', '');

        $items = Inventory::query()
            ->when($q !== '', fn ($query) => $query->searchBy(['brand', 'model_name', 'sku', 'barcode'], $q))
            ->when($type !== '', fn ($query) => $query->where('item_type', $type))
            ->when($stock === 'low', fn ($query) => $query->whereColumn('stock_qty', '<=', 'min_alert_qty'))
            ->when($stock === 'out', fn ($query) => $query->where('stock_qty', 0))
            ->orderBy('brand')
            ->paginate(50)
            ->withQueryString();

        // Live search/filter (fetched by Alpine) — return lightweight JSON rows.
        if ($request->wantsJson()) {
            return response()->json([
                'items' => $items->getCollection()->map(function (Inventory $i) {
                    $isOut = $i->stock_qty === 0;
                    $isLow = ! $isOut && $i->stock_qty <= $i->min_alert_qty;

                    return [
                        'id' => $i->id,
                        'brand' => $i->brand,
                        'model_name' => $i->model_name,
                        'item_type' => $i->item_type,
                        'type_label' => $i->type_label,
                        'sku' => $i->sku,
                        'barcode' => $i->barcode,
                        'cost_price' => auth()->user()->isStoreAdmin() ? (float) $i->cost_price : null,
                        'selling_price' => (float) $i->selling_price,
                        'stock_qty' => $i->stock_qty,
                        'is_out' => $isOut,
                        'is_low' => $isLow,
                        'url' => auth()->user()->isStoreAdmin() ? route('tenant.inventory.edit', $i) : route('tenant.inventory.show', $i),
                    ];
                })->values(),
                'total' => $items->total(),
                'has_more' => $items->hasMorePages(),
            ]);
        }

        return view('tenant.inventory.index', compact('items', 'q', 'type', 'stock'));
    }

    public function create(): View
    {
        return view('tenant.inventory.create');
    }

    public function store(InventoryRequest $request, SkuService $sku): RedirectResponse
    {
        $data = $request->validated();

        // Regenerate until unique within this tenant (the global scope keeps the
        // existence checks tenant-scoped), so a rare collision can't 500 on the
        // unique index or silently produce a duplicate barcode.
        do {
            $data['sku'] = $sku->generateSku($data['item_type'], $data['brand'] ?? null);
        } while (Inventory::where('sku', $data['sku'])->exists());

        do {
            $data['barcode'] = $sku->generateBarcode();
        } while (Inventory::where('barcode', $data['barcode'])->exists());

        Inventory::create($data);

        return redirect()->route('tenant.inventory.index')->with('status', 'Item added to inventory.');
    }

    public function show(Inventory $inventory): View
    {
        return view('tenant.inventory.show', ['item' => $inventory]);
    }

    public function edit(Inventory $inventory): View
    {
        $movements = $inventory->stockMovements()
            ->with('recorder:id,name')
            ->latest()
            ->limit(20)
            ->get();

        return view('tenant.inventory.edit', ['item' => $inventory, 'movements' => $movements]);
    }

    public function update(InventoryRequest $request, Inventory $inventory): RedirectResponse
    {
        // SKU + barcode are immutable after creation (matches the original).
        $inventory->update($request->validated());

        return redirect()->route('tenant.inventory.index')->with('status', 'Item updated.');
    }

    /**
     * FG-StockLog — apply a manual stock adjustment (damage / loss / recount)
     * with a reason, and record it in the item's movement ledger. Stock can
     * never go negative.
     */
    public function adjustStock(Request $request, Inventory $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $delta = (int) $validated['delta'];

        if ($inventory->stock_qty + $delta < 0) {
            return back()->with('error', "Adjustment would drop stock below zero (current: {$inventory->stock_qty}).");
        }

        DB::transaction(function () use ($inventory, $delta, $validated) {
            $inventory->increment('stock_qty', $delta);

            StockMovement::create([
                'inventory_id' => $inventory->id,
                'delta' => $delta,
                'type' => 'adjustment',
                'reason' => $validated['reason'],
                'recorded_by' => auth()->id(),
            ]);
        });

        return redirect()->route('tenant.inventory.edit', $inventory)
            ->with('status', 'Stock adjusted and logged.');
    }

    /** FG-Delete — archived (soft-deleted) items, restorable for 30 days. */
    public function trash(Request $request): View
    {
        // UX-04 — live archive search, filtered in SQL so it covers the whole
        // archive rather than just the rows on the current page.
        $search = trim((string) $request->query('search', ''));

        $items = Inventory::onlyTrashed()
            ->when($search !== '', fn ($query) => $query->searchBy(['sku', 'barcode', 'brand', 'model_name'], $search))
            ->latest('deleted_at')
            ->paginate(50)
            ->withQueryString();

        if ($request->ajax()) {
            return view('tenant.inventory.partials.trash-rows', compact('items', 'search'));
        }

        return view('tenant.inventory.trash', compact('items', 'search'));
    }

    /**
     * FG-Delete — archive an item (soft delete). Blocked while referenced by an
     * open order (pending / ready_for_pickup), since that stock is still
     * committed. Delivered/cancelled history is safe — those line items carry
     * their own captured unit_price.
     */
    public function destroy(Inventory $inventory): RedirectResponse
    {
        $openReference = $inventory->orderItems()
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'ready_for_pickup']))
            ->exists();

        if ($openReference) {
            return back()->with('error', 'This item is on an open order and cannot be archived until those orders are delivered or cancelled.');
        }

        $inventory->delete();

        return redirect()
            ->route('tenant.inventory.index')
            ->with('status', 'Item archived. You can restore it within 30 days.');
    }

    /** FG-Delete — restore an archived item. */
    public function restore(Inventory $inventory): RedirectResponse
    {
        $inventory->restore();

        return redirect()
            ->route('tenant.inventory.edit', $inventory)
            ->with('status', 'Item restored.');
    }

    /** FG-Delete — permanently delete an archived item (irreversible). */
    public function forceDelete(Inventory $inventory): RedirectResponse
    {
        // DATA-03 — an item referenced by any past order must never be hard-deleted:
        // `order_items.inventory_id` is nullOnDelete, so deleting it would blank the
        // link and every historical receipt/invoice line would degrade to "Custom
        // item". Keep it archived instead.
        if ($inventory->orderItems()->exists()) {
            return back()->with('error', 'This item appears on past orders and cannot be permanently deleted — it stays archived so those receipts stay intact.');
        }

        $inventory->forceDelete();

        return redirect()
            ->route('tenant.inventory.trash')
            ->with('status', 'Item permanently deleted.');
    }

    /**
     * AJAX barcode/SKU lookup (replaces the Supabase client query in BarcodeScanModal).
     */
    public function scan(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('q', ''));

        if ($code === '') {
            return response()->json(['found' => false]);
        }

        $item = Inventory::where('barcode', $code)
            ->orWhere('sku', $code)
            ->first(['id', 'sku', 'brand', 'model_name', 'stock_qty', 'selling_price']);

        if (! $item) {
            return response()->json(['found' => false, 'code' => $code]);
        }

        return response()->json([
            'found' => true,
            'item' => [
                'id' => $item->id,
                'sku' => $item->sku,
                'brand' => $item->brand,
                'model_name' => $item->model_name,
                'stock_qty' => $item->stock_qty,
                'selling_price' => (float) $item->selling_price,
                'edit_url' => route('tenant.inventory.edit', $item->id),
            ],
        ]);
    }
}

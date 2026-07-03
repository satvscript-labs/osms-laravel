<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\EyeRecord;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Barryvdh\DomPDF\Facade\Pdf;

class OrderController extends Controller
{
    /**
     * Orders workspace. Defaults to a scalable, searchable/filterable/sortable
     * table; `?view=kanban` falls back to the drag-and-drop workflow board.
     */
    public function index(Request $request): View|Response
    {
        $view = $request->query('view') === 'kanban' ? 'kanban' : 'table';

        // At-a-glance KPIs across the whole tenant dataset (cheap aggregates,
        // not row loads — these stay constant regardless of the active filters).
        $stats = [
            'total'       => Order::count(),
            'pending'     => Order::where('status', 'pending')->count(),
            'ready'       => Order::where('status', 'ready_for_pickup')->count(),
            'outstanding' => (float) Order::where('status', '!=', 'cancelled')
                                ->where('balance_due', '>', 0)->sum('balance_due'),
        ];

        // ---- Kanban: grouped by status (workflow board) ----
        if ($view === 'kanban') {
            $orders = Order::with('customer:id,name,phone')
                ->withCount('items')
                ->latest()
                ->get()
                ->groupBy('status');

            return view('tenant.orders.index', [
                'view' => 'kanban',
                'orders' => $orders,
                'stats' => $stats,
            ]);
        }

        // ---- Table: search + filter + sort + paginate ----
        // Capture the live-swap flag, then drop it so it never leaks into the
        // sort/pagination URLs baked by withQueryString() (a JS-disabled click
        // would otherwise serve a bare, layout-less partial).
        $isPartial = $request->boolean('partial');
        $request->query->remove('partial');

        $search  = trim((string) $request->query('q', ''));
        $status  = $request->query('status', '');
        $payment = $request->query('payment', '');

        $sortable = ['created_at', 'total_amount', 'balance_due'];
        $sort = in_array($request->query('sort'), $sortable, true) ? $request->query('sort') : 'created_at';
        $dir  = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $orders = Order::with('customer:id,name,phone')
            ->withCount('items')
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('customer', function ($c) use ($search) {
                    $c->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, ['pending', 'ready_for_pickup', 'delivered', 'cancelled'], true),
                fn ($query) => $query->where('status', $status))
            ->when($payment === 'outstanding', fn ($query) => $query->where('status', '!=', 'cancelled')->where('balance_due', '>', 0))
            ->when($payment === 'paid', fn ($query) => $query->where('balance_due', '<=', 0))
            ->orderBy($sort, $dir)
            ->paginate(25)
            ->withQueryString();

        // Live search/filter/sort/paginate (fetched by Alpine) — re-render just the
        // results partial and swap it in. Keeps one Blade source of truth for the
        // table (sort headers, pagination, row actions) instead of a parallel JS
        // template, while staying reload-free per the liquid-motion standard.
        if ($isPartial) {
            return response()->view('tenant.orders.partials._results', [
                'orders'  => $orders,
                'search'  => $search,
                'status'  => $status,
                'payment' => $payment,
                'sort'    => $sort,
                'dir'     => $dir,
            ]);
        }

        return view('tenant.orders.index', [
            'view'    => 'table',
            'orders'  => $orders,
            'stats'   => $stats,
            'search'  => $search,
            'status'  => $status,
            'payment' => $payment,
            'sort'    => $sort,
            'dir'     => $dir,
        ]);
    }

    public function create(Request $request): View
    {
        $customers = Customer::orderBy('name')->get(['id', 'name', 'phone']);
        $inventory = Inventory::where('stock_qty', '>', 0)
            ->orderBy('brand')
            ->get(['id', 'sku', 'barcode', 'brand', 'model_name', 'selling_price', 'stock_qty']);

        $selectedCustomerId = $request->query('customer');

        return view('tenant.orders.create', compact('customers', 'inventory', 'selectedCustomerId'));
    }

    public function store(Request $request): RedirectResponse
    {
        // Inline walk-in add: normalise the new-customer phone (code + national)
        // to the stored "{code} {national}" shape before validation.
        if (! $request->filled('customer_id') && $request->filled('customer_phone')) {
            $code = trim((string) ($request->input('customer_country_code') ?: '+91'));
            $national = preg_replace('/\D/', '', (string) $request->input('customer_phone'));
            $request->merge(['customer_phone' => $national !== '' ? $code . ' ' . $national : '']);
        }

        $validated = $request->validate([
            // Either pick an existing customer, or supply a new name + phone inline.
            'customer_id'    => ['nullable', 'required_without:customer_name', 'exists:customers,id'],
            'customer_name'  => ['nullable', 'required_without:customer_id', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'required_with:customer_name', 'string', 'max:30', 'regex:/^\+\d{1,4}\s\d{7,15}$/'],
            'eye_record_id' => ['nullable', 'exists:eye_records,id'],
            'fulfillment_type' => ['nullable', 'in:instant,special'],
            // Required only for a special order (a prepared job needs a promised date).
            'estimated_ready_at' => ['nullable', 'date', 'after_or_equal:today', 'required_if:fulfillment_type,special'],
            'advance_paid' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'in:cash,card,upi,other'],
            'discount_type' => ['nullable', 'in:none,percent,amount'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_id' => ['required', 'exists:inventory,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ], [
            'customer_phone.regex' => 'Enter a valid phone number (7–15 digits).',
            'estimated_ready_at.required_if' => 'A special order needs an estimated ready date.',
        ]);

        $order = DB::transaction(function () use ($validated) {
            // Resolve the customer: an existing one (tenant-checked → 404 if not) or
            // find-or-create by phone for an inline walk-in add. An existing phone
            // reuses that customer (never overwrites the name); the unique
            // (tenant_id, phone) index backstops against duplicates.
            $customer = ! empty($validated['customer_id'])
                ? Customer::findOrFail($validated['customer_id'])
                : Customer::firstOrCreate(
                    ['tenant_id' => auth()->user()->tenant_id, 'phone' => $validated['customer_phone']],
                    ['name' => $validated['customer_name']],
                );

            // A prescription, if attached, must belong to this customer (the exists
            // rule above is unscoped, so re-check it here).
            if (! empty($validated['eye_record_id'])) {
                EyeRecord::where('customer_id', $customer->id)
                    ->findOrFail($validated['eye_record_id']);
            }

            // Total quantity requested per item (collapses duplicate lines so the
            // stock guard can't be bypassed by splitting one item across two rows).
            $wanted = [];
            foreach ($validated['items'] as $line) {
                $id = $line['inventory_id'];
                $wanted[$id] = ($wanted[$id] ?? 0) + (int) $line['quantity'];
            }

            // Load + lock each item once. The tenant scope still applies, so a
            // cross-tenant inventory_id simply won't be found (404 below).
            $inventories = Inventory::lockForUpdate()
                ->findMany(array_keys($wanted))
                ->keyBy('id');

            // Guard against overselling before we mutate anything.
            foreach ($wanted as $id => $qty) {
                $inv = $inventories->get($id);

                if (! $inv) {
                    abort(404);
                }

                if ($qty > $inv->stock_qty) {
                    throw ValidationException::withMessages([
                        'items' => "Only {$inv->stock_qty} × {$inv->brand} {$inv->model_name} in stock (requested {$qty}).",
                    ]);
                }
            }

            // Build line items: list_price = the item's current list/MRP; unit_price
            // = a per-line custom override when supplied, else the list price (both
            // resolved server-side — never trust a client total). Subtotal is gross.
            $subtotal = 0;
            $lines = [];
            foreach ($validated['items'] as $line) {
                $inv = $inventories->get($line['inventory_id']);
                $qty = (int) $line['quantity'];
                $list = (float) $inv->selling_price;
                $unit = isset($line['unit_price']) ? round((float) $line['unit_price'], 2) : $list;
                $subtotal += $unit * $qty;
                $lines[] = [
                    'inventory_id' => $inv->id,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'list_price' => $list,
                ];
            }
            $subtotal = round($subtotal, 2);

            // Resolve the order-level discount, clamped so the total never goes negative.
            [$discountType, $discountValue, $discountAmount] = $this->resolveDiscount(
                $validated['discount_type'] ?? 'none',
                (float) ($validated['discount_value'] ?? 0),
                $subtotal,
            );
            $total = round($subtotal - $discountAmount, 2);

            $advance = min((float) ($validated['advance_paid'] ?? 0), $total);

            // Fulfillment: an instant sale is complete on the spot (created already
            // delivered, never enters the prep pipeline); a special order starts
            // pending and carries its promised ready date. Default to special so a
            // programmatic create without the field keeps today's behavior.
            $fulfillmentType = ($validated['fulfillment_type'] ?? 'special') === 'instant' ? 'instant' : 'special';
            $status = $fulfillmentType === 'instant' ? 'delivered' : 'pending';
            $estimatedReadyAt = $fulfillmentType === 'special' ? ($validated['estimated_ready_at'] ?? null) : null;

            $order = Order::create([
                'customer_id' => $customer->id,
                'eye_record_id' => $validated['eye_record_id'] ?? null,
                'status' => $status,
                'fulfillment_type' => $fulfillmentType,
                'estimated_ready_at' => $estimatedReadyAt,
                'subtotal' => $subtotal,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'total_amount' => $total,
                'advance_paid' => $advance,
            ]);

            $order->items()->createMany($lines);

            // Draw down stock now that the order is committed, logging each
            // movement so the item's stock ledger stays complete (FG-StockLog).
            foreach ($wanted as $id => $qty) {
                $inventories->get($id)->decrement('stock_qty', $qty);

                StockMovement::create([
                    'inventory_id' => $id,
                    'delta' => -$qty,
                    'type' => 'order',
                    'reason' => 'Order placed',
                    'order_id' => $order->id,
                    'recorded_by' => auth()->id(),
                ]);
            }

            // Record the initial advance as the first payment (FG-PaymentLog),
            // so the payment history is a complete ledger from the start.
            if ($advance > 0) {
                Payment::create([
                    'order_id' => $order->id,
                    'amount' => $advance,
                    'method' => $validated['payment_method'] ?? 'cash',
                    'note' => 'Initial advance',
                    'recorded_by' => auth()->id(),
                ]);
            }

            return $order;
        });

        return redirect()->route('tenant.orders.show', $order)->with('status', 'Order created.');
    }

    public function show(Order $order): View
    {
        $order->load([
            'customer',
            'eyeRecord',
            'items.inventory:id,sku,brand,model_name',
            'payments' => fn ($q) => $q->latest(),
            'payments.recorder:id,name',
        ]);
        $tenant = $order->tenant;

        return view('tenant.orders.show', compact('order', 'tenant'));
    }

    /**
     * FG-OrderEdit — edit an order's line items. Only a still-open order
     * (pending / ready_for_pickup) can be edited; a delivered order is a closed
     * transaction and a cancelled order has already had its stock restored.
     */
    public function edit(Order $order): View|RedirectResponse
    {
        if (! $this->isEditable($order)) {
            return redirect()->route('tenant.orders.show', $order)
                ->with('error', 'Only pending or ready-for-pickup orders can be edited.');
        }

        $order->load(['customer', 'items.inventory:id,sku,brand,model_name,stock_qty']);

        // Items already on the order — seed the builder. `max_stock` includes the
        // quantity this order already holds (conceptually returned first), so the
        // current quantity is always valid even if the item is now low/out of stock.
        $lineItems = $order->items->map(fn ($it) => [
            'inventory_id' => $it->inventory_id,
            'label' => trim(($it->inventory?->brand ?? '—') . ' · ' . ($it->inventory?->model_name ?? '')),
            'unit_price' => (float) $it->unit_price,
            'list_price' => (float) ($it->list_price ?? $it->unit_price),
            'quantity' => (int) $it->quantity,
            'max_stock' => (int) ($it->inventory?->stock_qty ?? 0) + (int) $it->quantity,
        ])->values();

        // Searchable inventory to add NEW lines (in-stock only, same as create).
        $inventory = Inventory::where('stock_qty', '>', 0)
            ->orderBy('brand')
            ->get(['id', 'sku', 'barcode', 'brand', 'model_name', 'selling_price', 'stock_qty']);

        // Prescriptions available to (re)attach.
        $eyeRecords = $order->customer->eyeRecords()->get(['id', 'created_at'])
            ->map(fn ($r) => ['id' => $r->id, 'label' => 'Rx · ' . $r->created_at->format('d M Y')]);

        return view('tenant.orders.edit', compact('order', 'inventory', 'lineItems', 'eyeRecords'));
    }

    /**
     * FG-OrderEdit — reconcile stock + money for an edited order in one atomic
     * transaction: diff old vs new quantities, re-run the oversell guard on
     * increases, adjust stock both directions (logging each net change), and
     * recompute the total. Payments/advance are untouched (owned by
     * recordPayment); the balance re-derives from the model's saving hook.
     */
    public function update(Request $request, Order $order): RedirectResponse
    {
        if (! $this->isEditable($order)) {
            return redirect()->route('tenant.orders.show', $order)
                ->with('error', 'Only pending or ready-for-pickup orders can be edited.');
        }

        $validated = $request->validate([
            'eye_record_id' => ['nullable', 'exists:eye_records,id'],
            // Owner may slip the promised date on an open order (no past-date guard here).
            'estimated_ready_at' => ['nullable', 'date'],
            'discount_type' => ['nullable', 'in:none,percent,amount'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_id' => ['required', 'exists:inventory,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        // A re-attached prescription must belong to this order's customer (the
        // exists rule above is unscoped).
        if (! empty($validated['eye_record_id'])) {
            EyeRecord::where('customer_id', $order->customer_id)
                ->findOrFail($validated['eye_record_id']);
        }

        DB::transaction(function () use ($order, $validated) {
            // Requested quantity + any posted custom unit price per item (collapse
            // duplicate lines; the builder emits one line per inventory_id).
            $wanted = [];
            $postedPrice = [];
            foreach ($validated['items'] as $line) {
                $id = $line['inventory_id'];
                $wanted[$id] = ($wanted[$id] ?? 0) + (int) $line['quantity'];
                if (isset($line['unit_price'])) {
                    $postedPrice[$id] = round((float) $line['unit_price'], 2);
                }
            }

            // Existing quantities + captured unit/list prices, keyed by inventory_id.
            $order->loadMissing('items');
            $oldQty = [];
            $oldUnit = [];
            $oldList = [];
            foreach ($order->items as $it) {
                $oldQty[$it->inventory_id] = ($oldQty[$it->inventory_id] ?? 0) + (int) $it->quantity;
                $oldUnit[$it->inventory_id] = (float) $it->unit_price;
                $oldList[$it->inventory_id] = $it->list_price !== null ? (float) $it->list_price : (float) $it->unit_price;
            }

            // Lock every item this edit touches (old ∪ new). Open-order items can
            // never be archived (C1 guard), so the default scope resolves them all.
            $ids = array_values(array_unique(array_merge(array_keys($wanted), array_keys($oldQty))));
            $inventories = Inventory::lockForUpdate()->findMany($ids)->keyBy('id');

            // Oversell guard: the *additional* draw beyond what this order already
            // holds must fit in current stock. A new item has old qty 0.
            foreach ($wanted as $id => $qty) {
                $inv = $inventories->get($id);
                if (! $inv) {
                    abort(404); // cross-tenant / unknown / archived new item
                }

                $additional = $qty - ($oldQty[$id] ?? 0);
                if ($additional > $inv->stock_qty) {
                    throw ValidationException::withMessages([
                        'items' => "Only {$inv->stock_qty} more × {$inv->brand} {$inv->model_name} available (need {$additional} more).",
                    ]);
                }
            }

            // Recompute the subtotal. Per line: unit_price = a posted custom override,
            // else the existing captured price (existing line) or the item's current
            // list price (newly-added line — untouched lines never silently reprice);
            // list_price = the existing snapshot, or current list for a new line.
            $subtotal = 0;
            $lines = [];
            foreach ($wanted as $id => $qty) {
                $inv = $inventories->get($id);
                $list = $oldList[$id] ?? (float) $inv->selling_price;
                $unit = round((float) ($postedPrice[$id] ?? $oldUnit[$id] ?? $inv->selling_price), 2);
                $subtotal += $unit * $qty;
                $lines[] = [
                    'inventory_id' => $id,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'list_price' => $list,
                ];
            }
            $subtotal = round($subtotal, 2);

            // Discount: use the posted type/value, else preserve the order's existing
            // discount. Re-resolve against the new subtotal so it stays clamped.
            [$discountType, $discountValue, $discountAmount] = $this->resolveDiscount(
                $validated['discount_type'] ?? $order->discount_type,
                (float) ($validated['discount_value'] ?? $order->discount_value),
                $subtotal,
            );
            $total = round($subtotal - $discountAmount, 2);

            // Can't shrink the order below what has already been paid — there's no
            // refund flow. The user must reconcile payments first.
            if ($total < (float) $order->advance_paid) {
                throw ValidationException::withMessages([
                    'items' => '₹ ' . number_format($order->advance_paid, 2) . ' has already been paid; '
                        . 'the new total (₹ ' . number_format($total, 2) . ') cannot be lower. Adjust payments first.',
                ]);
            }

            // Apply the net stock change for every touched item + log it.
            foreach ($ids as $id) {
                $delta = ($oldQty[$id] ?? 0) - ($wanted[$id] ?? 0); // + restores, − draws down
                if ($delta === 0) {
                    continue;
                }

                $inv = $inventories->get($id);
                if (! $inv) {
                    continue; // defensive: removed-line item vanished
                }

                $delta > 0 ? $inv->increment('stock_qty', $delta) : $inv->decrement('stock_qty', -$delta);

                StockMovement::create([
                    'inventory_id' => $id,
                    'delta' => $delta,
                    'type' => 'edit',
                    'reason' => 'Order edited',
                    'order_id' => $order->id,
                    'recorded_by' => auth()->id(),
                ]);
            }

            // Replace the line items and update the order (advance untouched; the
            // saving hook re-derives balance_due).
            $order->items()->delete();
            $order->items()->createMany($lines);
            $order->update([
                'eye_record_id' => $validated['eye_record_id'] ?? null,
                'estimated_ready_at' => $validated['estimated_ready_at'] ?? $order->estimated_ready_at,
                'subtotal' => $subtotal,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'total_amount' => $total,
            ]);
        });

        return redirect()->route('tenant.orders.show', $order)->with('status', 'Order updated.');
    }

    /** Only still-open orders (pending / ready_for_pickup) may be edited. */
    private function isEditable(Order $order): bool
    {
        return in_array($order->status, ['pending', 'ready_for_pickup'], true);
    }

    /**
     * Resolve an order-level discount to a clamped [type, value, amount] triple.
     * Percent is capped 0–100; a flat amount is capped at the subtotal, so the
     * total can never go negative. All amounts are rounded to 2dp.
     */
    private function resolveDiscount(string $type, float $value, float $subtotal): array
    {
        if ($type === 'percent') {
            $value = max(0.0, min($value, 100.0));

            return ['percent', round($value, 2), round($subtotal * $value / 100, 2)];
        }

        if ($type === 'amount') {
            $value = max(0.0, min($value, $subtotal));

            return ['amount', round($value, 2), round($value, 2)];
        }

        return ['none', 0.0, 0.0];
    }

    /** Advance an order to the next workflow status. */
    public function updateStatus(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,ready_for_pickup,delivered'],
        ]);

        $order->update(['status' => $validated['status']]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $order->status]);
        }

        return back()->with('status', 'Order updated.');
    }

    /**
     * NB-009 — cancel an order and return its stock. A delivered or
     * already-cancelled order can't be cancelled (idempotent + safe).
     */
    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($order->isCancelled()) {
            return back()->with('error', 'This order is already cancelled.');
        }

        if ($order->status === 'delivered') {
            return back()->with('error', 'A delivered order cannot be cancelled.');
        }

        DB::transaction(function () use ($order, $validated) {
            // Restore each line's stock and log the reversal (FG-StockLog).
            $order->loadMissing('items');

            foreach ($order->items as $item) {
                $inv = Inventory::lockForUpdate()->find($item->inventory_id);
                if (! $inv) {
                    continue; // item was deleted; nothing to restore
                }

                $inv->increment('stock_qty', $item->quantity);

                StockMovement::create([
                    'inventory_id' => $inv->id,
                    'delta' => $item->quantity,
                    'type' => 'cancel',
                    'reason' => 'Order cancelled',
                    'order_id' => $order->id,
                    'recorded_by' => auth()->id(),
                ]);
            }

            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => $validated['cancel_reason'] ?? null,
            ]);
        });

        return back()->with('status', 'Order cancelled and stock restored.');
    }

    /**
     * FG-PaymentLog — record a payment against the order and advance the
     * running total (capped at `total_amount`, keeping balance_due ≥ 0).
     */
    public function recordPayment(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'method' => ['required', 'in:cash,card,upi,other'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($order->isCancelled()) {
            return back()->with('error', 'You cannot record a payment on a cancelled order.');
        }

        if ((float) $order->balance_due <= 0) {
            return back()->with('error', 'This order is already fully paid.');
        }

        // Never accept more than what is outstanding.
        $amount = min((float) $validated['amount'], (float) $order->balance_due);

        DB::transaction(function () use ($order, $validated, $amount) {
            Payment::create([
                'order_id' => $order->id,
                'amount' => $amount,
                'method' => $validated['method'],
                'note' => $validated['note'] ?? null,
                'recorded_by' => auth()->id(),
            ]);

            // Bump the running advance (model's saving hook re-derives balance_due).
            $order->update([
                'advance_paid' => (float) $order->advance_paid + $amount,
            ]);
        });

        return back()->with('status', 'Payment of ₹ ' . number_format($amount, 2) . ' recorded.');
    }

    /** Printable / downloadable PDF receipt (DomPDF). */
    public function pdf(Order $order)
    {
        $order->load(['customer', 'eyeRecord', 'items.inventory:id,sku,brand,model_name', 'payments']);
        $tenant = $order->tenant;

        $pdf = Pdf::loadView('tenant.orders.receipt-pdf', compact('order', 'tenant'))
            ->setPaper('a4');

        return $pdf->stream('receipt-' . substr($order->id, 0, 8) . '.pdf');
    }

    /** JSON list of a customer's eye records for the order builder. */
    public function eyeRecords(Customer $customer): JsonResponse
    {
        $records = $customer->eyeRecords()->get(['id', 'created_at'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'label' => 'Rx · ' . $r->created_at->format('d M Y'),
            ]);

        return response()->json($records);
    }
}

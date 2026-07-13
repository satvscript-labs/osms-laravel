<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\EyeRecord;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\TaxInvoice;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
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

        // Resolve the store's WhatsApp config once (real row or a Manual default)
        // so every card can decide whether to show a manual "Send" pill (FT-WhatsApp).
        $waConfig = $this->waConfig();

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
                'waConfig' => $waConfig,
                'waPending' => $this->waPendingFor($orders->flatten(1)->pluck('id')),
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
        $waPending = $this->waPendingFor($orders->getCollection()->pluck('id'));

        if ($isPartial) {
            return response()->view('tenant.orders.partials._results', [
                'orders'  => $orders,
                'search'  => $search,
                'status'  => $status,
                'payment' => $payment,
                'sort'    => $sort,
                'dir'     => $dir,
                'waConfig' => $waConfig,
                'waPending' => $waPending,
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
            'waConfig' => $waConfig,
            'waPending' => $waPending,
        ]);
    }

    /**
     * FT-WhatsApp — the pending (still-cancellable) automated messages for a set
     * of orders, keyed by order_id. Only ready/delivered events surface an undo
     * ring; a placed receipt sends quietly.
     */
    private function waPendingFor($ids): Collection
    {
        $ids = collect($ids)->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return WhatsAppMessage::whereIn('order_id', $ids)
            ->whereIn('event', ['order_ready', 'order_delivered'])
            ->where('status', 'scheduled')
            ->where('scheduled_for', '>', now())
            ->latest('scheduled_for')
            ->get()
            ->keyBy('order_id');
    }

    /** The store's WhatsApp config (existing row or a zero-config Manual default). */
    private function waConfig(): WhatsAppConfig
    {
        $tenant = auth()->user()->tenant;

        return $tenant->whatsappConfig ?? WhatsAppConfig::defaultFor($tenant);
    }

    /**
     * FT-WhatsApp — schedule an automated message for an order event (a no-op for
     * Off / Manual stores; the manual pill covers those). Called after the write
     * commits. Never lets a messaging hiccup break the order flow.
     */
    private function scheduleWhatsApp(Order $order, string $event): void
    {
        try {
            app(WhatsAppScheduler::class)->handle($order, $event);
        } catch (\Throwable $e) {
            report($e); // messaging must never break placing/advancing an order
        }
    }

    /**
     * FT-WhatsApp (Phase 6) — the "undo" behind the countdown ring. While an
     * automated ready/delivered message is still within its grace window, cancel
     * it and step the order back one status. Reverting delivered→ready uses a
     * direct status write (NOT updateStatus), so it never re-schedules the
     * already-sent ready message. Refused once the message has gone out.
     */
    public function revert(Request $request, Order $order): JsonResponse
    {
        $message = WhatsAppMessage::where('order_id', $order->id)
            ->whereIn('event', ['order_ready', 'order_delivered'])
            ->where('status', 'scheduled')
            ->where('scheduled_for', '>', now())
            ->latest('scheduled_for')
            ->first();

        if (! $message) {
            return response()->json([
                'ok' => false,
                'message' => 'Too late to undo — the message has already been sent.',
            ], 422);
        }

        // event → [status it advanced TO, status to revert back to]
        [$advancedTo, $revertTo] = match ($message->event) {
            'order_ready' => ['ready_for_pickup', 'pending'],
            'order_delivered' => ['delivered', 'ready_for_pickup'],
            default => [null, null],
        };

        $message->update(['status' => 'cancelled']);

        if ($revertTo !== null && $order->status === $advancedTo) {
            $order->update(['status' => $revertTo]); // direct write — no re-trigger
        }

        return response()->json(['ok' => true, 'status' => $order->status]);
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
            // Each line is EITHER a catalog item (inventory_id) OR a local/custom
            // item (free-text description + required price) — enforced by the closure
            // rule (required_without can't pair sibling wildcard fields by index).
            'items' => ['required', 'array', 'min:1', $this->validItemsRule()],
            'items.*.inventory_id' => ['nullable', 'exists:inventory,id'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            // FT-TaxInvoice — per-line opt-in, only the items a customer wants a
            // formal invoice for (e.g. a branded frame), not necessarily the whole order.
            'items.*.tax_invoice' => ['nullable', 'boolean'],
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

            // Partition posted lines: catalog lines draw down stock; local/custom
            // lines (6.4) are free-text, untracked — no stock, no lock, no guard.
            [$catalog, $custom] = $this->partitionItems($validated['items']);

            // Total quantity requested per catalog item (collapses duplicate lines so
            // the stock guard can't be bypassed by splitting one item across two rows).
            $wanted = [];
            foreach ($catalog as $line) {
                $id = $line['inventory_id'];
                $wanted[$id] = ($wanted[$id] ?? 0) + (int) $line['quantity'];
            }

            // Load + lock each catalog item once. The tenant scope still applies, so a
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
            foreach ($catalog as $line) {
                $inv = $inventories->get($line['inventory_id']);
                $qty = (int) $line['quantity'];
                $list = (float) $inv->selling_price;
                $unit = isset($line['unit_price']) ? round((float) $line['unit_price'], 2) : $list;
                $subtotal += $unit * $qty;
                $lines[] = [
                    'inventory_id' => $inv->id,
                    'description' => null,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'list_price' => $list,
                    'on_tax_invoice' => (bool) ($line['tax_invoice'] ?? false),
                ];
            }
            // Local/custom lines (6.4): no inventory, list_price = unit_price (no MRP
            // concept), description sentence-cased. Price is required by the rule above.
            foreach ($custom as $line) {
                $qty = (int) $line['quantity'];
                $unit = round((float) ($line['unit_price'] ?? 0), 2);
                $subtotal += $unit * $qty;
                $lines[] = [
                    'inventory_id' => null,
                    'description' => $this->normaliseDescription((string) ($line['description'] ?? '')),
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'list_price' => $unit,
                    'on_tax_invoice' => (bool) ($line['tax_invoice'] ?? false),
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

            // FT-TaxInvoice — issue the formal invoice now (same transaction as the
            // order) when at least one line was opted in, so the two can never
            // disagree: either both exist or neither does.
            if (collect($lines)->contains('on_tax_invoice', true)) {
                TaxInvoice::issueFor($order);
            }

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

        // Automated receipt (D-INSTANT: instant sales get the receipt only, never a
        // separate delivered message). No-op for Off/Manual stores.
        $this->scheduleWhatsApp($order, 'order_placed');

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
        $waConfig = $this->waConfig();
        $waPending = $this->waPendingFor([$order->id]);
        // FT-TaxInvoice — only surface the button when the invoice exists AND at
        // least one item is currently flagged (an edit could have unflagged all of them).
        $taxInvoice = $order->items->contains('on_tax_invoice', true) ? $order->taxInvoice : null;

        return view('tenant.orders.show', compact('order', 'tenant', 'waConfig', 'waPending', 'taxInvoice'));
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

        // Items already on the order — seed the builder. Each line carries a stable
        // `uid` so the Alpine x-for key never collides (multiple custom lines all
        // have a null inventory_id — 6.4). For a catalog line `max_stock` includes
        // the quantity this order already holds (conceptually returned first), so the
        // current quantity is always valid even if the item is now low/out of stock;
        // a custom line is untracked (max_stock null → no cap).
        $lineItems = $order->items->map(fn ($it) => [
            'uid' => 's' . substr($it->id, 0, 12),
            'inventory_id' => $it->inventory_id,
            'description' => $it->description,
            'custom' => $it->is_custom,
            'label' => $it->is_custom
                ? ($it->description ?: 'Custom item')
                : trim(($it->inventory?->brand ?? '—') . ' · ' . ($it->inventory?->model_name ?? '')),
            'unit_price' => (float) $it->unit_price,
            'list_price' => (float) ($it->list_price ?? $it->unit_price),
            'quantity' => (int) $it->quantity,
            'max_stock' => $it->is_custom ? null : (int) ($it->inventory?->stock_qty ?? 0) + (int) $it->quantity,
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
            'items' => ['required', 'array', 'min:1', $this->validItemsRule()],
            'items.*.inventory_id' => ['nullable', 'exists:inventory,id'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'items.*.tax_invoice' => ['nullable', 'boolean'],
        ]);

        // A re-attached prescription must belong to this order's customer (the
        // exists rule above is unscoped).
        if (! empty($validated['eye_record_id'])) {
            EyeRecord::where('customer_id', $order->customer_id)
                ->findOrFail($validated['eye_record_id']);
        }

        DB::transaction(function () use ($order, $validated) {
            // Partition posted lines. Catalog lines reconcile against inventory
            // (keyed by inventory_id); local/custom lines (6.4) never touch stock and
            // are simply replaced wholesale — so they can't be keyed by inventory_id
            // (all null → they'd collide with each other). This partition is what
            // makes two custom lines on one order survive an edit.
            [$catalog, $custom] = $this->partitionItems($validated['items']);

            // Requested quantity + any posted custom unit price per CATALOG item
            // (collapse duplicate lines; the builder emits one line per inventory_id).
            $wanted = [];
            $postedPrice = [];
            $postedTaxInvoice = [];
            foreach ($catalog as $line) {
                $id = $line['inventory_id'];
                $wanted[$id] = ($wanted[$id] ?? 0) + (int) $line['quantity'];
                if (isset($line['unit_price'])) {
                    $postedPrice[$id] = round((float) $line['unit_price'], 2);
                }
                if (array_key_exists('tax_invoice', $line)) {
                    $postedTaxInvoice[$id] = (bool) $line['tax_invoice'];
                }
            }

            // Existing CATALOG quantities + captured unit/list prices, keyed by
            // inventory_id. Existing custom lines carry no stock, so they're excluded
            // from the diff (they're dropped + rebuilt from the posted custom lines).
            $order->loadMissing('items');
            $oldQty = [];
            $oldUnit = [];
            $oldList = [];
            $oldTaxInvoice = [];
            foreach ($order->items as $it) {
                if ($it->inventory_id === null) {
                    continue; // local/custom line — no stock reconciliation
                }
                $oldQty[$it->inventory_id] = ($oldQty[$it->inventory_id] ?? 0) + (int) $it->quantity;
                $oldUnit[$it->inventory_id] = (float) $it->unit_price;
                $oldList[$it->inventory_id] = $it->list_price !== null ? (float) $it->list_price : (float) $it->unit_price;
                // FT-TaxInvoice — the edit form has no toggle for this yet, so a
                // previously-flagged catalog item must survive an unrelated edit
                // (e.g. a quantity bump) rather than silently losing its invoice flag.
                $oldTaxInvoice[$it->inventory_id] = $oldTaxInvoice[$it->inventory_id] ?? (bool) $it->on_tax_invoice;
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
                    'description' => null,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'list_price' => $list,
                    // Preserve unless this edit explicitly posts a value for it.
                    'on_tax_invoice' => $postedTaxInvoice[$id] ?? ($oldTaxInvoice[$id] ?? false),
                ];
            }
            // Local/custom lines (6.4): rebuilt fresh each edit (no stock diff), with
            // list_price = unit_price and a sentence-cased description. There's no
            // stable key to match a custom line to its pre-edit self, so (like its
            // price) the invoice flag only survives if this edit re-posts it.
            foreach ($custom as $line) {
                $qty = (int) $line['quantity'];
                $unit = round((float) ($line['unit_price'] ?? 0), 2);
                $subtotal += $unit * $qty;
                $lines[] = [
                    'inventory_id' => null,
                    'description' => $this->normaliseDescription((string) ($line['description'] ?? '')),
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'list_price' => $unit,
                    'on_tax_invoice' => (bool) ($line['tax_invoice'] ?? false),
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

            // FT-TaxInvoice — issue the invoice if this edit is what first flags an
            // item (idempotent; a no-op once one already exists for this order).
            if (collect($lines)->contains('on_tax_invoice', true)) {
                TaxInvoice::issueFor($order);
            }
        });

        return redirect()->route('tenant.orders.show', $order)->with('status', 'Order updated.');
    }

    /** Only still-open orders (pending / ready_for_pickup) may be edited. */
    private function isEditable(Order $order): bool
    {
        return in_array($order->status, ['pending', 'ready_for_pickup'], true);
    }

    /**
     * FT-LocalItems (6.4) — split posted lines into [catalog, custom]. A catalog
     * line has an `inventory_id` (stock-tracked); a custom/local line has none and
     * carries a free-text `description` instead.
     */
    private function partitionItems(array $items): array
    {
        $catalog = [];
        $custom = [];
        foreach ($items as $line) {
            if (! empty($line['inventory_id'])) {
                $catalog[] = $line;
            } else {
                $custom[] = $line;
            }
        }

        return [$catalog, $custom];
    }

    /**
     * FT-LocalItems (6.4) — closure rule: every posted line must be EITHER a catalog
     * item (`inventory_id`) OR a priced custom item (`description` + `unit_price`).
     * A per-index closure is required because `required_without` can't pair sibling
     * array-wildcard fields by index.
     */
    private function validItemsRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            foreach ((array) $value as $i => $line) {
                $hasInventory = ! empty($line['inventory_id']);
                $hasDescription = isset($line['description']) && trim((string) $line['description']) !== '';

                if (! $hasInventory && ! $hasDescription) {
                    $fail('Line ' . ($i + 1) . ' needs a catalog item or a custom description.');
                } elseif (! $hasInventory && ! isset($line['unit_price'])) {
                    $fail('The custom item on line ' . ($i + 1) . ' needs a price.');
                }
            }
        };
    }

    /**
     * FT-LocalItems (6.4-D2) — sentence-case a custom description: if the first
     * character is lowercase, capitalize just it. The rest is left untouched so
     * intentional casing ("UV400", "Ray-Ban style") is never mangled. A leading
     * digit/symbol has no uppercase form, so it's left as-is.
     */
    private function normaliseDescription(string $description): string
    {
        $description = trim($description);
        if ($description === '') {
            return $description;
        }

        $first = mb_substr($description, 0, 1);
        if (mb_strtoupper($first) !== $first) {
            $description = mb_strtoupper($first) . mb_substr($description, 1);
        }

        return $description;
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

        // Automated ready/delivered message (no-op for Off/Manual; dedupe means a
        // status that bounces back and forward never re-sends).
        if ($event = WhatsAppConfig::eventForStatus($order->status)) {
            $this->scheduleWhatsApp($order, $event);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $order->status]);
        }

        return back()->with('status', 'Order updated.');
    }

    /**
     * 6.1 / 6.3 — settle an order: optionally apply a last-moment discount, record
     * a payment, and (when marking delivered from the orders board) advance the
     * status. One atomic transaction reusing the same discount + payment-capping
     * math as store()/recordPayment(), so nothing is recomputed a second way.
     *
     * Called two ways:
     *  • from the orders board's "Settle & deliver" modal (JSON, mark_delivered=1)
     *  • from the analytics Pending-Dues "Settle" button (form POST, no status change)
     */
    public function settle(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'method' => ['required_with:amount', 'in:cash,card,upi,other'],
            'note' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['nullable', 'in:none,percent,amount'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'mark_delivered' => ['nullable', 'boolean'],
        ]);

        if ($order->isCancelled()) {
            return $this->settleError($request, 'You cannot settle a cancelled order.');
        }

        try {
            DB::transaction(function () use ($validated, $order) {
                // 1) Last-moment discount (optional): re-resolve against the order's
                // existing subtotal, then recompute the total. It can never drop the
                // total below what has already been paid (no refund flow — same guard
                // as update()).
                if (! empty($validated['discount_type'])) {
                    [$dType, $dValue, $dAmount] = $this->resolveDiscount(
                        $validated['discount_type'],
                        (float) ($validated['discount_value'] ?? 0),
                        (float) $order->subtotal,
                    );
                    $newTotal = round((float) $order->subtotal - $dAmount, 2);

                    if ($newTotal < (float) $order->advance_paid) {
                        throw ValidationException::withMessages([
                            'discount_value' => '₹ ' . number_format($order->advance_paid, 2)
                                . ' has already been paid; a discount cannot drop the total below that.',
                        ]);
                    }

                    $order->update([
                        'discount_type' => $dType,
                        'discount_value' => $dValue,
                        'discount_amount' => $dAmount,
                        'total_amount' => $newTotal,
                    ]);
                }

                // 2) Payment (optional): capped at the balance remaining *after* any
                // discount above, so we never over-collect.
                $amount = min((float) ($validated['amount'] ?? 0), (float) $order->balance_due);
                if ($amount > 0) {
                    Payment::create([
                        'order_id' => $order->id,
                        'amount' => $amount,
                        'method' => $validated['method'] ?? 'cash',
                        'note' => $validated['note'] ?? 'Settled on delivery',
                        'recorded_by' => auth()->id(),
                    ]);

                    $order->update([
                        'advance_paid' => (float) $order->advance_paid + $amount,
                    ]);
                }

                // 3) Advance to delivered when the board asked for it (6.1). The dues
                // settlement (6.3) never sets this, so status is untouched there.
                if (! empty($validated['mark_delivered']) && $order->status !== 'delivered') {
                    $order->update(['status' => 'delivered']);
                }
            });
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
            }
            throw $e;
        }

        // Automated thank-you when this settle marked the order delivered (6.1).
        if (! empty($validated['mark_delivered']) && $order->fresh()->status === 'delivered') {
            $this->scheduleWhatsApp($order->fresh(), 'order_delivered');
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $order->fresh()->status]);
        }

        return back()->with('status', 'Order settled.');
    }

    /** Shared error response for settle() — JSON for the board, redirect for a form. */
    private function settleError(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $message], 422);
        }

        return back()->with('error', $message);
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

    /**
     * FT-TaxInvoice — the formal tax invoice PDF, covering only the items the
     * shop owner opted in for (not necessarily the whole order). 404s when
     * nothing is currently flagged, so a stale link can't leak an empty invoice.
     */
    public function taxInvoicePdf(Order $order)
    {
        $order->load(['customer', 'items.inventory:id,sku,brand,model_name,item_type', 'taxInvoice']);
        $tenant = $order->tenant;
        $invoice = $order->taxInvoice;
        $items = $order->items->where('on_tax_invoice', true);

        if (! $invoice || $items->isEmpty()) {
            abort(404);
        }

        $pdf = Pdf::loadView('tenant.orders.tax-invoice-pdf', compact('order', 'tenant', 'invoice', 'items'))
            ->setPaper('a4');

        return $pdf->stream($invoice->number . '.pdf');
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

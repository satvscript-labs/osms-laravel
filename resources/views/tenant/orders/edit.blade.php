@extends('layouts.app')
@section('title', 'Edit order')

@php $num = strtoupper(substr($order->id, 0, 8)); @endphp

@section('content')
<div class="p-4 p-md-5" x-data="orderEditBuilder()" x-init="init()">
    <a href="{{ route('tenant.orders.show', $order) }}"
       class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3" style="font-size:var(--text-sm);">
        <i class="bi bi-chevron-left"></i> Back to order
    </a>
    <p class="section-label mb-1">Edit order</p>
    <h1 class="h3 fw-semibold font-display mb-4">Order #{{ $num }}</h1>

    @if ($errors->any())
        <div class="alert alert-danger py-2 px-3 small rounded-3">
            <ul class="mb-0 ps-3">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('tenant.orders.update', $order) }}" @submit="validateForm($event)">
        @csrf
        @method('PUT')
        <input type="hidden" name="eye_record_id" :value="eyeRecordId">
        <input type="hidden" name="discount_type" :value="discountType()">
        <input type="hidden" name="discount_value" :value="discountValue || 0">
        @if ($order->needsPrep())
            <input type="hidden" name="estimated_ready_at" :value="estimatedReadyAt">
        @endif
        {{-- Keyed by uid, not inventory_id: multiple local/custom lines all have a
             null inventory_id and would collide (6.4). Each posts EITHER an
             inventory_id (catalog) OR a description (custom), never an empty one. --}}
        <template x-for="(it, idx) in items" :key="it.uid">
            <span>
                <template x-if="it.inventory_id">
                    <input type="hidden" :name="`items[${idx}][inventory_id]`" :value="it.inventory_id">
                </template>
                <template x-if="!it.inventory_id">
                    <input type="hidden" :name="`items[${idx}][description]`" :value="it.description">
                </template>
                <input type="hidden" :name="`items[${idx}][quantity]`" :value="it.quantity">
                <input type="hidden" :name="`items[${idx}][unit_price]`" :value="it.unit_price">
            </span>
        </template>

        <div class="row g-4">
            {{-- Left column --}}
            <div class="col-lg-8 d-flex flex-column gap-4">
                {{-- Customer (locked on edit) --}}
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <h2 class="section-label mb-3">Customer</h2>
                        <div class="d-flex align-items-center justify-content-between gap-3">
                            <div class="d-flex align-items-center gap-3">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary flex-shrink-0"
                                      style="width:2.5rem;height:2.5rem;"><i class="bi bi-person"></i></span>
                                <div>
                                    <p class="mb-0 fw-medium">{{ $order->customer->name }}</p>
                                    <p class="mb-0 text-muted-foreground small">{{ $order->customer->phone }}</p>
                                </div>
                            </div>
                            <span class="badge text-bg-light flex-shrink-0">Fixed</span>
                        </div>

                        {{-- Eye record select --}}
                        <div x-cloak x-show="eyeRecords.length" class="mt-3">
                            <label class="form-label small fw-medium mb-1">Attach prescription (optional)</label>
                            <select class="form-select" x-model="eyeRecordId">
                                <option value="">No prescription</option>
                                <template x-for="r in eyeRecords" :key="r.id">
                                    <option :value="r.id" x-text="r.label"></option>
                                </template>
                            </select>
                        </div>

                        {{-- Estimated ready date (special orders) --}}
                        @if ($order->needsPrep())
                            <div class="mt-3" style="max-width:16rem;">
                                <label for="estimatedReadyAt" class="form-label small fw-medium mb-1">Estimated ready date</label>
                                <input id="estimatedReadyAt" type="date" class="form-control" x-model="estimatedReadyAt">
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Line items --}}
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h2 class="section-label mb-0">Line items</h2>
                            <span class="badge text-bg-light"><i class="bi bi-upc-scan me-1"></i>Scanner ready</span>
                        </div>

                        <p x-show="scanFlash" x-text="scanFlash"
                           class="bg-primary-subtle text-primary rounded-3 px-3 py-2 small mb-3"></p>

                        <div class="position-relative mb-3">
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-search text-muted-foreground"></i></span>
                                <input type="text" class="form-control" placeholder="Type to search inventory, or scan a barcode…"
                                       x-model="itemSearch" data-barcode-target>
                            </div>
                            <div class="list-group position-absolute w-100 shadow-sm" style="z-index:5;"
                                 x-show="itemSearch.length > 0 && filteredInventory().length">
                                <template x-for="inv in filteredInventory()" :key="inv.id">
                                    <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between"
                                            @click="addItem(inv); itemSearch=''">
                                        <span>
                                            <span class="fw-medium" x-text="inv.brand || '—'"></span>
                                            <span class="text-muted-foreground" x-text="inv.model_name"></span>
                                            <span class="d-block text-muted-foreground" style="font-size:var(--text-xs);"
                                                  x-text="inv.sku + ' · stock ' + inv.stock_qty"></span>
                                        </span>
                                        <span class="font-monospace small" x-text="money(inv.selling_price)"></span>
                                    </button>
                                </template>
                            </div>
                        </div>

                        {{-- Add a local/custom item (6.4): something the store carries but
                             never catalogued. Not stock-tracked. --}}
                        <div class="mb-3">
                            <button type="button" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1"
                                    @click="toggleCustom()" :aria-expanded="customMode.toString()">
                                <i class="bi" :class="customMode ? 'bi-dash-lg' : 'bi-plus-lg'"></i>
                                <span x-text="customMode ? 'Close custom item' : 'Add custom item'"></span>
                            </button>
                            <div class="reveal mt-2" :class="customMode ? 'reveal-open' : ''">
                                <div class="reveal-inner">
                                    <div class="rounded-3 p-3" style="background: var(--surface-sunken);">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-12 col-sm">
                                                <label class="form-label small fw-medium mb-1">Item name</label>
                                                <input type="text" class="form-control form-control-sm" x-model="customName"
                                                       placeholder="e.g. Lens cleaning kit" @keydown.enter.prevent="addCustomItem()">
                                            </div>
                                            <div class="col-6 col-sm-auto" style="max-width:8rem;">
                                                <label class="form-label small fw-medium mb-1">Price (₹)</label>
                                                <input type="number" min="0" step="0.01" class="form-control form-control-sm text-end font-monospace"
                                                       x-model="customPrice" placeholder="0" @keydown.enter.prevent="addCustomItem()">
                                            </div>
                                            <div class="col-6 col-sm-auto" style="max-width:6rem;">
                                                <label class="form-label small fw-medium mb-1">Qty</label>
                                                <input type="number" min="1" step="1" class="form-control form-control-sm text-center"
                                                       x-model="customQty" @keydown.enter.prevent="addCustomItem()">
                                            </div>
                                            <div class="col-12 col-sm-auto">
                                                <button type="button" class="btn btn-primary btn-sm w-100" @click="addCustomItem()"
                                                        :disabled="!canAddCustom()">
                                                    <i class="bi bi-plus-lg me-1"></i> Add
                                                </button>
                                            </div>
                                        </div>
                                        <p class="text-muted-foreground mb-0 mt-2" style="font-size:var(--text-xs);">
                                            Not tracked in inventory — no stock is drawn down. Counts toward the order total.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div x-cloak x-show="items.length === 0" class="text-center text-muted-foreground py-4 border border-2 border-dashed rounded-3">
                            No items — search, scan, or add a custom item. An order must have at least one item.
                        </div>

                        <div class="table-responsive" x-cloak x-show="items.length">
                            <table class="table align-top mb-0">
                                <thead class="text-muted-foreground text-uppercase" style="font-size:var(--text-2xs);letter-spacing:.04em;">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center" style="width:7.5rem;">Qty</th>
                                        <th class="text-end" style="width:9.5rem;">Unit price</th>
                                        <th class="text-end" style="width:7rem;">Total</th>
                                        <th style="width:2.5rem;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="it in items" :key="it.inventory_id">
                                        <tr>
                                            <td>
                                                <span class="fw-medium d-block" x-text="it.label"></span>
                                                <template x-if="it.custom">
                                                    <span class="osms-badge osms-badge-blue" style="font-size:var(--text-3xs);">
                                                        <span class="osms-badge-dot"></span> Local item
                                                    </span>
                                                </template>
                                                <template x-if="!it.custom">
                                                    <span class="text-faint" style="font-size:var(--text-2xs);" x-text="'List ' + money(it.list_price)"></span>
                                                </template>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm mx-auto" style="width:6.75rem;">
                                                    <button type="button" class="btn btn-light" @click="changeQty(it,-1)" aria-label="Decrease quantity"><i class="bi bi-dash-lg"></i></button>
                                                    <input type="text" class="form-control text-center px-0" :value="it.quantity" readonly>
                                                    <button type="button" class="btn btn-light" @click="changeQty(it,1)" aria-label="Increase quantity"><i class="bi bi-plus-lg"></i></button>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm ms-auto" style="width:8.5rem;">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" min="0" step="0.01"
                                                           class="form-control text-end font-monospace px-1"
                                                           x-model.number="it.unit_price"
                                                           @blur="normalisePrice(it)"
                                                           :class="{'text-primary fw-medium': it.unit_price != it.list_price}"
                                                           aria-label="Unit price">
                                                </div>
                                                <div class="text-end mt-1" style="height:.9rem;">
                                                    <button type="button" x-show="it.unit_price != it.list_price"
                                                            class="btn btn-link btn-sm p-0 text-decoration-none text-muted-foreground"
                                                            style="font-size:var(--text-2xs);" @click="it.unit_price = it.list_price">
                                                        <i class="bi bi-arrow-counterclockwise"></i> reset to list
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex align-items-center justify-content-end" style="min-height:var(--space-6);">
                                                    <span class="font-monospace" x-text="money((Number(it.unit_price)||0)*it.quantity)"></span>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex align-items-center justify-content-center" style="min-height:var(--space-6);">
                                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="removeItem(it)" aria-label="Remove item">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Summary --}}
            <div class="col-lg-4">
                <div class="glass card-lift rounded-4 p-4 position-sticky" style="top:1.5rem;">
                    <h2 class="section-label mb-3">Summary</h2>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted-foreground">Items</span>
                        <span class="fw-medium" x-text="itemCount()"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted-foreground">Subtotal</span>
                        <span class="fw-medium font-monospace" x-text="money(subtotal())"></span>
                    </div>

                    {{-- Discount --}}
                    <div class="mb-1">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground">Discount</span>
                            <span class="font-monospace" :class="discountAmount() > 0 ? 'text-success fw-medium' : 'text-faint'"
                                  x-text="discountAmount() > 0 ? '− ' + money(discountAmount()) : '—'"></span>
                        </div>
                        <div class="input-group input-group-sm">
                            <button type="button" class="btn" :class="unit === '%' ? 'btn-primary' : 'btn-light'" @click="unit = '%'; normaliseDiscount()" style="width:2.75rem;">%</button>
                            <button type="button" class="btn" :class="unit === '₹' ? 'btn-primary' : 'btn-light'" @click="unit = '₹'; normaliseDiscount()" style="width:2.75rem;">₹</button>
                            <input type="number" min="0" step="0.01" :max="discountMax()" class="form-control text-end font-monospace"
                                   x-model.number="discountValue" @input="normaliseDiscount()" @blur="normaliseDiscount()"
                                   placeholder="0" aria-label="Discount value">
                        </div>
                        <p x-cloak x-show="discountAmount() > 0" class="text-success mb-0 mt-1" style="font-size:var(--text-2xs);"
                           x-text="savingsLabel()"></p>
                        <p x-cloak x-show="discountCapped()" class="text-faint mb-0 mt-1" style="font-size:var(--text-2xs);"
                           x-text="unit === '%' ? 'Capped at 100%' : 'Capped at the subtotal (' + money(subtotal()) + ')'"></p>
                    </div>

                    <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-3">
                        <span class="fw-semibold">Total</span>
                        <span class="h5 fw-semibold font-display font-monospace mb-0" x-text="money(total())"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <span class="text-muted-foreground">Already paid</span>
                        <span class="fw-medium font-monospace">₹ {{ number_format($order->advance_paid, 2) }}</span>
                    </div>
                    <p x-cloak x-show="total() < advancePaid" class="text-danger small mt-2 mb-0">
                        Total can't be below the ₹ {{ number_format($order->advance_paid, 2) }} already paid.
                    </p>

                    <div class="bg-primary-subtle rounded-3 p-3 mt-3">
                        <p class="text-uppercase text-primary mb-1" style="font-size:var(--text-2xs);letter-spacing:.05em;">Balance due</p>
                        <p class="h4 fw-semibold font-display mb-0" x-text="money(Math.max(balance(), 0))"></p>
                    </div>
                    <p class="text-muted-foreground mt-2 mb-0" style="font-size:var(--text-xs);">
                        Payments are managed from the order page. Editing changes items, prices, and discount.
                    </p>
                    <button type="submit" class="btn btn-primary w-100 mt-3" :disabled="!canSubmit()">
                        <i class="bi bi-check-lg me-1"></i> Save changes
                    </button>
                    <a href="{{ route('tenant.orders.show', $order) }}" class="btn btn-light w-100 mt-2">Cancel</a>
                </div>
            </div>
        </div>
    </form>
</div>

@push('scripts')
@include('partials.barcode-listener', ['onScan' => 'orderScan'])
<script>
    window.orderScan = (code) => window.dispatchEvent(new CustomEvent('osms-barcode', { detail: code }));

    function orderEditBuilder() {
        return {
            inventory: @json($inventory),
            eyeRecords: @json($eyeRecords),
            eyeRecordId: @json($order->eye_record_id ?? ''),
            items: @json($lineItems),
            advancePaid: {{ (float) $order->advance_paid }},
            unit: @json($order->discount_type === 'amount' ? '₹' : '%'),
            discountValue: @json($order->discount_type === 'none' ? '' : (float) $order->discount_value),
            estimatedReadyAt: @json(optional($order->estimated_ready_at)->toDateString() ?? ''),
            itemSearch: '', scanFlash: null,
            customMode: false, customName: '', customPrice: '', customQty: 1,

            init() {
                window.addEventListener('osms-barcode', (e) => this.onScan(e.detail));
            },
            money(n) { return '₹ ' + Number(n || 0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); },
            uid() { return 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7); },
            filteredInventory() {
                const q = this.itemSearch.trim().toLowerCase();
                if (!q) return [];
                return this.inventory.filter(i =>
                    [i.brand,i.model_name,i.sku,i.barcode].some(v => v && v.toLowerCase().includes(q))).slice(0,6);
            },
            addItem(inv, qty=1) {
                const ex = this.items.find(i => i.inventory_id === inv.id);
                if (ex) { ex.quantity = Math.min(ex.max_stock, ex.quantity + qty); return; }
                const price = Number(inv.selling_price);
                this.items.push({
                    uid: this.uid(), custom: false,
                    inventory_id: inv.id, description: null,
                    label: (inv.brand||'—') + (inv.model_name ? ' · '+inv.model_name : ''),
                    unit_price: price, list_price: price,
                    quantity: Math.min(inv.stock_qty, qty), max_stock: inv.stock_qty,
                });
            },
            canAddCustom() {
                const p = Number(this.customPrice);
                return this.customName.trim() !== '' && !isNaN(p) && p >= 0;
            },
            toggleCustom() { this.customMode = !this.customMode; },
            addCustomItem() {
                if (!this.canAddCustom()) return;
                const price = Math.round(Number(this.customPrice) * 100) / 100;
                const qty = Math.max(1, parseInt(this.customQty) || 1);
                this.items.push({
                    uid: this.uid(), custom: true,
                    inventory_id: null, description: this.customName.trim(),
                    label: this.customName.trim(),
                    unit_price: price, list_price: price,
                    quantity: qty, max_stock: null, // untracked — no stock cap
                });
                this.customName = ''; this.customPrice = ''; this.customQty = 1; this.customMode = false;
            },
            // A catalog line is capped at stock; a custom line (max_stock null) is uncapped.
            changeQty(it, delta) {
                const cap = it.max_stock == null ? Infinity : it.max_stock;
                it.quantity = Math.min(Math.max(1, it.quantity + delta), cap);
            },
            normalisePrice(it) {
                let v = Number(it.unit_price);
                if (isNaN(v) || v < 0 || it.unit_price === '' || it.unit_price === null) v = it.list_price;
                it.unit_price = Math.round(v * 100) / 100;
                // A custom line has no MRP: keep list_price in step (no phantom discount).
                if (it.custom) it.list_price = it.unit_price;
            },
            removeItem(it) { this.items = this.items.filter(i => i.uid !== it.uid); },
            onScan(code) {
                const m = this.inventory.find(i => i.barcode === code || i.sku === code);
                if (m) { this.addItem(m,1); this.flash(`Added ${m.brand||'item'} ${m.model_name||''}`.trim()); }
                else { this.flash(`No item matches "${code}"`); }
            },
            flash(msg) { this.scanFlash = msg; setTimeout(()=> this.scanFlash=null, 2000); },
            subtotal() { return this.items.reduce((s,i)=> s + (Number(i.unit_price)||0)*i.quantity, 0); },
            discountAmount() {
                const st = this.subtotal();
                const v = Number(this.discountValue) || 0;
                if (v <= 0 || st <= 0) return 0;
                const raw = this.unit === '%' ? st * Math.min(v, 100) / 100 : Math.min(v, st);
                return Math.round(Math.min(raw, st) * 100) / 100;
            },
            discountType() { return (Number(this.discountValue) || 0) > 0 ? (this.unit === '%' ? 'percent' : 'amount') : 'none'; },
            discountMax() { return this.unit === '%' ? 100 : (this.subtotal() || 0); },
            discountCapped() {
                const v = Number(this.discountValue) || 0;
                return v > 0 && v >= this.discountMax() && this.discountMax() > 0;
            },
            normaliseDiscount() {
                if (this.discountValue === '' || this.discountValue === null) return;
                let v = Number(this.discountValue);
                if (isNaN(v) || v < 0) { this.discountValue = ''; return; }
                const cap = this.discountMax();
                if (cap > 0 && v > cap) v = cap;
                this.discountValue = Math.round(v * 100) / 100;
            },
            savingsLabel() {
                const st = this.subtotal(), d = this.discountAmount();
                if (d <= 0 || st <= 0) return '';
                return `You save ${this.money(d)} (${Math.round(d/st*1000)/10}%)`;
            },
            total() { return Math.max(this.subtotal() - this.discountAmount(), 0); },
            itemCount() { return this.items.reduce((s,i)=> s + i.quantity, 0); },
            balance() { return this.total() - this.advancePaid; },
            canSubmit() { return this.items.length > 0 && this.total() >= this.advancePaid; },
            validateForm(e) {
                if (!this.canSubmit()) {
                    e.preventDefault();
                    if (this.total() < this.advancePaid) { alert('The total cannot be below what has already been paid.'); }
                    return false;
                }
                return true;
            },
        };
    }
</script>
@endpush
@endsection

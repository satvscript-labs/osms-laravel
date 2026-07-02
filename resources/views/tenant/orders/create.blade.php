@extends('layouts.app')
@section('title', 'New order')

@section('content')
<div class="p-4 p-md-5" x-data="orderBuilder()" x-init="init()">
    <a href="{{ route('tenant.orders.index') }}"
       class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3" style="font-size:.8rem;">
        <i class="bi bi-chevron-left"></i> Back to orders
    </a>
    <p class="section-label mb-1">New order</p>
    <h1 class="h3 fw-semibold font-display mb-4">Create order</h1>

    @if ($errors->any())
        <div class="alert alert-danger py-2 px-3 small rounded-3">
            <ul class="mb-0 ps-3">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('tenant.orders.store') }}" @submit="validateForm($event)">
        @csrf
        <input type="hidden" name="customer_id" :value="customerId">
        <input type="hidden" name="customer_name" :value="newMode ? newName.trim() : ''">
        <input type="hidden" name="customer_phone" :value="newMode ? newPhone : ''">
        <input type="hidden" name="customer_country_code" :value="newCode">
        <input type="hidden" name="eye_record_id" :value="eyeRecordId">
        <input type="hidden" name="advance_paid" :value="advancePaid">
        <input type="hidden" name="payment_method" :value="paymentMethod">
        <input type="hidden" name="discount_type" :value="discountType()">
        <input type="hidden" name="discount_value" :value="discountValue || 0">
        <template x-for="(it, idx) in items" :key="it.inventory_id">
            <span>
                <input type="hidden" :name="`items[${idx}][inventory_id]`" :value="it.inventory_id">
                <input type="hidden" :name="`items[${idx}][quantity]`" :value="it.quantity">
                <input type="hidden" :name="`items[${idx}][unit_price]`" :value="it.unit_price">
            </span>
        </template>

        <div class="row g-4">
            {{-- Left column --}}
            <div class="col-lg-8 d-flex flex-column gap-4">
                {{-- Customer picker --}}
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <h2 class="section-label mb-3">Customer</h2>

                        {{-- Search existing (nothing chosen, not adding) --}}
                        <div x-cloak x-show="!customerId && !newMode" class="position-relative">
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-search text-muted-foreground"></i></span>
                                <input type="text" class="form-control" placeholder="Search customer by name or phone…"
                                       x-model="customerSearch" data-barcode-target>
                            </div>
                            <div class="list-group position-absolute w-100 shadow-sm" style="z-index:5;"
                                 x-show="customerSearch.length > 0 && filteredCustomers().length">
                                <template x-for="c in filteredCustomers()" :key="c.id">
                                    <button type="button" class="list-group-item list-group-item-action"
                                            @click="selectCustomer(c)">
                                        <span class="fw-medium" x-text="c.name"></span>
                                        <span class="text-muted-foreground small" x-text="' · ' + c.phone"></span>
                                    </button>
                                </template>
                            </div>
                            {{-- Add-new affordance --}}
                            <div x-show="customerSearch.trim().length > 0" class="mt-2">
                                <button type="button" class="btn btn-sm btn-light" @click="startNew()">
                                    <i class="bi bi-person-plus me-1"></i> Add “<span x-text="customerSearch.trim()"></span>” as a new customer
                                </button>
                            </div>
                        </div>

                        {{-- Inline new-customer form --}}
                        <div x-cloak x-show="newMode" class="rounded-3 p-3" style="background: var(--surface-sunken);">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="fw-medium small">New customer</span>
                                <button type="button" class="btn btn-sm btn-link text-muted-foreground p-0 text-decoration-none" @click="cancelNew()">Cancel</button>
                            </div>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small fw-medium mb-1">Full name</label>
                                    <input type="text" class="form-control" x-model="newName" placeholder="Rahul Kumar">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-medium mb-1">Phone</label>
                                    <div class="input-group">
                                        <select class="form-select flex-grow-0 w-auto" x-model="newCode" aria-label="Country code">
                                            <template x-for="code in codes" :key="code"><option :value="code" x-text="code"></option></template>
                                        </select>
                                        <input type="tel" class="form-control" x-model="newPhone" placeholder="98765 43210">
                                    </div>
                                </div>
                            </div>
                            <p class="text-muted-foreground mb-0 mt-2" style="font-size:.72rem;">
                                Saved automatically when you create the order. An existing phone links to that customer.
                            </p>
                        </div>

                        {{-- Chosen existing customer --}}
                        <div x-cloak x-show="customerId" class="d-flex align-items-center justify-content-between gap-3">
                            <div class="d-flex align-items-center gap-3">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary flex-shrink-0"
                                      style="width:2.5rem;height:2.5rem;"><i class="bi bi-person"></i></span>
                                <div>
                                    <p class="mb-0 fw-medium" x-text="selectedCustomer?.name"></p>
                                    <p class="mb-0 text-muted-foreground small" x-text="selectedCustomer?.phone"></p>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-light flex-shrink-0" @click="clearCustomer()">Change</button>
                        </div>

                        {{-- Eye record select (existing customer with prescriptions) --}}
                        <div x-cloak x-show="customerId && eyeRecords.length" class="mt-3">
                            <label class="form-label small fw-medium mb-1">Attach prescription (optional)</label>
                            <select class="form-select" x-model="eyeRecordId">
                                <option value="">No prescription</option>
                                <template x-for="r in eyeRecords" :key="r.id">
                                    <option :value="r.id" x-text="r.label"></option>
                                </template>
                            </select>
                        </div>
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
                                            <span class="d-block text-muted-foreground" style="font-size:.72rem;"
                                                  x-text="inv.sku + ' · stock ' + inv.stock_qty"></span>
                                        </span>
                                        <span class="font-monospace small" x-text="money(inv.selling_price)"></span>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <div x-cloak x-show="items.length === 0" class="text-center text-muted-foreground py-4 border border-2 border-dashed rounded-3">
                            No items yet — search or scan to add.
                        </div>

                        <div class="table-responsive" x-cloak x-show="items.length">
                            <table class="table align-middle mb-0">
                                <thead class="text-muted-foreground text-uppercase" style="font-size:.68rem;letter-spacing:.04em;">
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
                                                <span class="text-faint" style="font-size:.7rem;" x-text="'List ' + money(it.list_price)"></span>
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
                                                            style="font-size:.68rem;" @click="it.unit_price = it.list_price">
                                                        <i class="bi bi-arrow-counterclockwise"></i> reset to list
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="text-end font-monospace" x-text="money((Number(it.unit_price)||0)*it.quantity)"></td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="removeItem(it)" aria-label="Remove item">
                                                    <i class="bi bi-trash"></i>
                                                </button>
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
                            <button type="button" class="btn" :class="unit === '%' ? 'btn-primary' : 'btn-light'" @click="unit = '%'" style="width:2.75rem;">%</button>
                            <button type="button" class="btn" :class="unit === '₹' ? 'btn-primary' : 'btn-light'" @click="unit = '₹'" style="width:2.75rem;">₹</button>
                            <input type="number" min="0" step="0.01" class="form-control text-end font-monospace"
                                   x-model.number="discountValue" placeholder="0" aria-label="Discount value">
                        </div>
                        <p x-cloak x-show="discountAmount() > 0" class="text-success mb-0 mt-1" style="font-size:.7rem;"
                           x-text="savingsLabel()"></p>
                    </div>

                    <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-3">
                        <span class="fw-semibold">Total</span>
                        <span class="h5 fw-semibold font-display font-monospace mb-0" x-text="money(total())"></span>
                    </div>

                    {{-- Advance + method --}}
                    <div class="border-top mt-3 pt-3">
                        <label class="form-label small fw-medium mb-1">Advance paid</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" step="0.01" min="0" class="form-control font-monospace" x-model="advancePaid">
                        </div>
                        <label class="form-label small fw-medium mb-1 mt-3">Payment method</label>
                        <select class="form-select" x-model="paymentMethod">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="upi">UPI</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="bg-primary-subtle rounded-3 p-3 mt-3">
                        <p class="text-uppercase text-primary mb-1" style="font-size:.68rem;letter-spacing:.05em;">Balance due</p>
                        <p class="h4 fw-semibold font-display mb-0" x-text="money(balance())"></p>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mt-3" :disabled="!canSubmit()">
                        <i class="bi bi-plus-lg me-1"></i> Create order
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

@push('scripts')
@include('partials.barcode-listener', ['onScan' => 'orderScan'])
<script>
    window.orderScan = (code) => window.dispatchEvent(new CustomEvent('osms-barcode', { detail: code }));

    function orderBuilder() {
        return {
            customers: @json($customers),
            inventory: @json($inventory),
            codes: ['+91', '+1', '+44', '+971', '+61', '+65', '+880', '+977'],
            customerId: '', selectedCustomer: null, customerSearch: '',
            newMode: false, newName: '', newPhone: '', newCode: '+91',
            eyeRecords: [], eyeRecordId: '',
            items: [], itemSearch: '', scanFlash: null,
            advancePaid: '0', paymentMethod: 'cash',
            unit: '%', discountValue: '',

            init() {
                const pre = @json($selectedCustomerId);
                if (pre) { const c = this.customers.find(x => x.id === pre); if (c) this.selectCustomer(c); }
                window.addEventListener('osms-barcode', (e) => this.onScan(e.detail));
            },
            money(n) { return '₹ ' + Number(n || 0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); },
            filteredCustomers() {
                const q = this.customerSearch.trim().toLowerCase();
                if (!q) return [];
                return this.customers.filter(c => (c.name+' '+c.phone).toLowerCase().includes(q)).slice(0,6);
            },
            filteredInventory() {
                const q = this.itemSearch.trim().toLowerCase();
                if (!q) return [];
                return this.inventory.filter(i =>
                    [i.brand,i.model_name,i.sku,i.barcode].some(v => v && v.toLowerCase().includes(q))).slice(0,6);
            },
            selectCustomer(c) {
                this.customerId = c.id; this.selectedCustomer = c; this.customerSearch = '';
                this.newMode = false; this.newName = ''; this.newPhone = '';
                fetch(`{{ url('tenant/customers') }}/${c.id}/eye-records`, {headers:{'Accept':'application/json'}})
                    .then(r => r.json()).then(d => { this.eyeRecords = d; this.eyeRecordId = d[0]?.id || ''; });
            },
            clearCustomer() { this.customerId=''; this.selectedCustomer=null; this.eyeRecords=[]; this.eyeRecordId=''; },
            startNew() { this.newName = this.customerSearch.trim(); this.customerSearch = ''; this.newMode = true; },
            cancelNew() { this.newMode = false; this.newName = ''; this.newPhone = ''; },
            addItem(inv, qty=1) {
                const ex = this.items.find(i => i.inventory_id === inv.id);
                if (ex) { ex.quantity = Math.min(ex.max_stock, ex.quantity + qty); return; }
                const price = Number(inv.selling_price);
                this.items.push({
                    inventory_id: inv.id,
                    label: (inv.brand||'—') + (inv.model_name ? ' · '+inv.model_name : ''),
                    unit_price: price, list_price: price,
                    quantity: Math.min(inv.stock_qty, qty), max_stock: inv.stock_qty,
                });
            },
            changeQty(it, delta) { it.quantity = Math.min(Math.max(1, it.quantity + delta), it.max_stock); },
            normalisePrice(it) {
                let v = Number(it.unit_price);
                if (isNaN(v) || v < 0 || it.unit_price === '' || it.unit_price === null) v = it.list_price;
                it.unit_price = Math.round(v * 100) / 100;
            },
            removeItem(it) { this.items = this.items.filter(i => i.inventory_id !== it.inventory_id); },
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
            savingsLabel() {
                const st = this.subtotal(), d = this.discountAmount();
                if (d <= 0 || st <= 0) return '';
                return `You save ${this.money(d)} (${Math.round(d/st*1000)/10}%)`;
            },
            total() { return Math.max(this.subtotal() - this.discountAmount(), 0); },
            itemCount() { return this.items.reduce((s,i)=> s + i.quantity, 0); },
            balance() { return Math.max(this.total() - (Number(this.advancePaid)||0), 0); },
            hasCustomer() { return this.customerId !== '' || (this.newMode && this.newName.trim() !== '' && this.newPhone.trim() !== ''); },
            canSubmit() { return this.hasCustomer() && this.items.length > 0; },
            validateForm(e) {
                if (!this.canSubmit()) { e.preventDefault(); return false; }
                return true;
            },
        };
    }
</script>
@endpush
@endsection

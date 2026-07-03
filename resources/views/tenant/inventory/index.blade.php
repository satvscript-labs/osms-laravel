@extends('layouts.app')
@section('title', 'Inventory')

@php
    // Icon per item type — mirrored in JS (typeIcon) for live rows.
    $typeIcon = fn ($t) => match ($t) {
        'frame' => 'bi-eyeglasses',
        'lens' => 'bi-circle',
        'contact_lens' => 'bi-droplet',
        'accessory' => 'bi-bag',
        default => 'bi-box-seam',
    };
@endphp

@section('content')
<div class="p-4 p-md-5"
     x-data="inventoryIndex({
        endpoint: @js(route('tenant.inventory.index')),
        exportBase: @js(route('tenant.inventory.export')),
        query: @js($q),
        type: @js($type),
        stock: @js($stock),
        serverTotal: {{ $items->total() }},
     })"
     @barcode-scan.window="query = $event.detail; refresh()">

    {{-- Header --}}
    <div class="d-flex flex-column flex-md-row gap-3 align-items-md-end justify-content-between mb-4">
        <div>
            <p class="section-label mb-1">Workspace</p>
            <h1 class="h3 fw-semibold font-display mb-1">Inventory</h1>
            <p class="text-muted-foreground mb-0 text-md">
                Frames, lenses, and accessories — scan a barcode anywhere to find an item.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a :href="exportUrl()" href="{{ route('tenant.inventory.export', ['q' => $q, 'type' => $type, 'stock' => $stock]) }}" class="btn btn-light">
                <i class="bi bi-download me-1"></i> Export
            </a>
            <a href="{{ route('tenant.inventory.trash') }}" class="btn btn-light">
                <i class="bi bi-archive me-1"></i> Archive
            </a>
            <a href="{{ route('tenant.inventory.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Add item
            </a>
        </div>
    </div>

    @if (request('scan'))
        <div class="alert alert-primary d-flex align-items-center gap-2 py-2 px-3 rounded-3 animate-fade-up" role="alert">
            <i class="bi bi-upc-scan fs-5"></i>
            <span class="small mb-0">Ready to scan — point your scanner at a barcode, or type to search below.</span>
        </div>
    @endif

    {{-- Search + filters --}}
    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center mb-3">
        <div class="input-group flex-nowrap" style="max-width:30rem;">
            <span class="input-group-text bg-white border-end-0">
                <i class="bi bi-search text-muted-foreground"></i>
            </span>
            <input id="searchInput" type="search" x-model="query" @input.debounce.220ms="refresh()"
                   class="form-control border-start-0"
                   placeholder="Brand, model, SKU, or barcode…" autocomplete="off" aria-label="Search inventory">
            <span class="input-group-text bg-white border-start-0" x-show="loading" x-cloak>
                <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
            </span>
            <button type="button" class="btn btn-light border-start-0" x-show="query && !loading" x-cloak
                    @click="query=''; refresh()" aria-label="Clear search">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <select x-model="type" @change="refresh()" class="form-select w-auto" aria-label="Filter by type">
            <option value="">All types</option>
            <option value="frame">Frames</option>
            <option value="lens">Lenses</option>
            <option value="contact_lens">Contact lenses</option>
            <option value="accessory">Accessories</option>
        </select>
        <select x-model="stock" @change="refresh()" class="form-select w-auto" aria-label="Filter by stock">
            <option value="">Any stock</option>
            <option value="low">Low stock</option>
            <option value="out">Out of stock</option>
        </select>
    </div>

    {{-- Result count --}}
    <p class="text-muted-foreground text-sm mb-3" x-cloak>
        <span x-text="displayTotal()"></span>
    </p>

    {{-- ============ LIVE (Alpine) results ============ --}}
    <template x-if="mode==='live'">
        <div>
            {{-- Skeleton while loading --}}
            <template x-if="loading">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <template x-for="i in 6" :key="i">
                        <div class="person-row">
                            <span class="skeleton" style="width:2.7rem;height:2.7rem;border-radius:var(--radius-lg);"></span>
                            <div class="flex-grow-1">
                                <div class="skeleton mb-2" style="height:.8rem;width:45%;"></div>
                                <div class="skeleton" style="height:.7rem;width:30%;"></div>
                            </div>
                            <div class="skeleton d-none d-md-block" style="height:1.4rem;width:3.5rem;border-radius:var(--radius-pill);"></div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Results --}}
            <template x-if="!loading && rows.length">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden stagger" :key="listKey">
                    <template x-for="it in rows" :key="it.id">
                        <a :href="it.url" class="person-row">
                            <span class="item-avatar"><i class="bi" :class="typeIcon(it.item_type)"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="fw-semibold text-truncate" x-text="it.brand || it.model_name"></span>
                                    <span class="text-muted-foreground text-truncate" x-show="it.brand" x-text="it.model_name"></span>
                                </div>
                                <div class="font-monospace text-faint text-xs mt-1">
                                    <span x-text="it.sku"></span>
                                    <span x-show="it.barcode"> · <span x-text="it.barcode"></span></span>
                                </div>
                            </div>
                            <span class="meta-chip d-none d-sm-inline-flex" x-text="it.type_label"></span>
                            <div class="d-none d-md-block text-end" style="min-width:6.5rem;">
                                <div class="fw-semibold" x-text="money(it.selling_price)"></div>
                                <div class="text-3xs text-faint">Cost <span x-text="money(it.cost_price)"></span></div>
                            </div>
                            <span class="stock-pill" :class="stockClass(it)">
                                <i class="bi" :class="{'bi-x-circle': it.is_out, 'bi-exclamation-triangle': it.is_low}" x-show="it.is_out || it.is_low"></i>
                                <span x-text="it.stock_qty"></span>
                            </span>
                            <i class="bi bi-chevron-right person-chevron"></i>
                        </a>
                    </template>
                </div>
            </template>

            {{-- "More results" hint --}}
            <template x-if="!loading && rows.length && hasMore">
                <p class="text-center text-muted-foreground text-sm mt-3">
                    Showing the first <span x-text="rows.length"></span>. Refine your search to narrow it down.
                </p>
            </template>

            {{-- Live empty state --}}
            <template x-if="!loading && !rows.length">
                <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
                    <span class="item-avatar mx-auto mb-3" style="width:3.25rem;height:3.25rem;font-size:1.4rem;"><i class="bi bi-search"></i></span>
                    <h2 class="h5 fw-semibold font-display">No items match your filters</h2>
                    <p class="text-muted-foreground mb-0">Try adjusting your search or filters.</p>
                </div>
            </template>
        </div>
    </template>

    {{-- ============ IDLE (server-rendered) results ============ --}}
    <div x-show="mode==='idle'">
        @if ($items->isNotEmpty())
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden stagger">
                @foreach ($items as $i)
                    @php $isOut = $i->stock_qty === 0; $isLow = !$isOut && $i->stock_qty <= $i->min_alert_qty; @endphp
                    <a href="{{ route('tenant.inventory.edit', $i) }}" class="person-row">
                        <span class="item-avatar"><i class="bi {{ $typeIcon($i->item_type) }}"></i></span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold text-truncate">{{ $i->brand ?? $i->model_name }}</span>
                                @if ($i->brand)
                                    <span class="text-muted-foreground text-truncate">{{ $i->model_name }}</span>
                                @endif
                            </div>
                            <div class="font-monospace text-faint text-xs mt-1">
                                {{ $i->sku }}@if ($i->barcode) · {{ $i->barcode }}@endif
                            </div>
                        </div>
                        <span class="meta-chip d-none d-sm-inline-flex">{{ $i->type_label }}</span>
                        <div class="d-none d-md-block text-end" style="min-width:6.5rem;">
                            <div class="fw-semibold">₹ {{ number_format($i->selling_price, 2) }}</div>
                            <div class="text-3xs text-faint">Cost ₹ {{ number_format($i->cost_price, 2) }}</div>
                        </div>
                        <span class="stock-pill {{ $isOut ? 'stock-pill-out' : ($isLow ? 'stock-pill-low' : 'stock-pill-ok') }}">
                            @if ($isOut)<i class="bi bi-x-circle"></i>@elseif ($isLow)<i class="bi bi-exclamation-triangle"></i>@endif
                            {{ $i->stock_qty }}
                        </span>
                        <i class="bi bi-chevron-right person-chevron"></i>
                    </a>
                @endforeach
            </div>

            @if ($items->hasPages())
                <div class="mt-3">{{ $items->links() }}</div>
            @endif
        @else
            <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
                <span class="item-avatar mx-auto mb-3" style="width:3.25rem;height:3.25rem;font-size:1.5rem;"><i class="bi bi-box-seam"></i></span>
                <h2 class="h5 fw-semibold font-display">No inventory yet</h2>
                <p class="text-muted-foreground mb-3">Add your first frame or lens to get started.</p>
                <a href="{{ route('tenant.inventory.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add item</a>
            </div>
        @endif
    </div>
</div>

@push('scripts')
@include('partials.barcode-listener', ['onScan' => 'fillSearch'])
<script>
    // Scanner → feed the code into the live Alpine search via a window event.
    function fillSearch(code) {
        window.dispatchEvent(new CustomEvent('barcode-scan', { detail: code }));
    }

    // NB-016: arriving via the dashboard "Scan barcode" shortcut focuses the search box.
    document.addEventListener('DOMContentLoaded', () => {
        if (new URLSearchParams(location.search).has('scan')) {
            document.getElementById('searchInput')?.focus();
        }
    });

    function inventoryIndex(config) {
        return {
            endpoint: config.endpoint,
            exportBase: config.exportBase,
            query: config.query || '',
            type: config.type || '',
            stock: config.stock || '',
            serverTotal: config.serverTotal || 0,
            mode: 'idle',          // 'idle' = server rows · 'live' = fetched rows
            loading: false,
            rows: [],
            total: 0,
            hasMore: false,
            listKey: 0,
            _controller: null,

            money(n) {
                return '₹ ' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },

            typeIcon(t) {
                return ({
                    frame: 'bi-eyeglasses',
                    lens: 'bi-circle',
                    contact_lens: 'bi-droplet',
                    accessory: 'bi-bag',
                }[t]) || 'bi-box-seam';
            },

            stockClass(it) {
                return it.is_out ? 'stock-pill-out' : (it.is_low ? 'stock-pill-low' : 'stock-pill-ok');
            },

            displayTotal() {
                const n = this.mode === 'live' ? this.total : this.serverTotal;
                return n + ' ' + (n === 1 ? 'item' : 'items');
            },

            get isDefaultView() {
                return this.query.trim() === '' && this.type === '' && this.stock === '';
            },

            exportUrl() {
                const u = new URL(this.exportBase, window.location.origin);
                if (this.query.trim()) u.searchParams.set('q', this.query.trim());
                if (this.type) u.searchParams.set('type', this.type);
                if (this.stock) u.searchParams.set('stock', this.stock);
                return u.toString();
            },

            _url() {
                const u = new URL(this.endpoint, window.location.origin);
                if (this.query.trim()) u.searchParams.set('q', this.query.trim());
                if (this.type) u.searchParams.set('type', this.type);
                if (this.stock) u.searchParams.set('stock', this.stock);
                return u.toString();
            },

            refresh() {
                window.history.replaceState({}, '', this._url());

                // Returning to the default view — show server-rendered rows, no fetch.
                if (this.isDefaultView) {
                    this.mode = 'idle';
                    this.rows = [];
                    if (this._controller) this._controller.abort();
                    this.loading = false;
                    return;
                }

                this.mode = 'live';
                this.loading = true;
                if (this._controller) this._controller.abort();
                this._controller = new AbortController();

                fetch(this._url(), {
                    headers: { 'Accept': 'application/json' },
                    signal: this._controller.signal,
                })
                    .then(r => r.json())
                    .then(d => {
                        this.rows = d.items || [];
                        this.total = d.total || 0;
                        this.hasMore = !!d.has_more;
                        this.loading = false;
                        this.listKey++;
                    })
                    .catch(err => { if (err.name !== 'AbortError') this.loading = false; });
            },
        };
    }
</script>
@endpush
@endsection

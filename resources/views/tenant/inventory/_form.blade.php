@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $types = ['frame' => 'Frame', 'lens' => 'Lens', 'contact_lens' => 'Contact lens', 'accessory' => 'Accessory'];
    $val = fn ($key, $default = '') => old($key, $item->{$key} ?? $default);
@endphp

@if ($errors->any())
    <div class="alert alert-danger py-2 px-3 small rounded-3">
        <ul class="mb-0 ps-3">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<form method="POST"
      action="{{ $isEdit ? route('tenant.inventory.update', $item) : route('tenant.inventory.store') }}"
      class="d-flex flex-column gap-4">
    @csrf
    @if ($isEdit) @method('PUT') @endif

    {{-- Identification --}}
    <div class="row g-3">
        <div class="col-sm-6">
            <label for="item_type" class="form-label small fw-medium mb-1">Item type *</label>
            <select id="item_type" name="item_type" class="form-select @error('item_type') is-invalid @enderror" {{ $isEdit ? 'disabled' : '' }}>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected($val('item_type', 'frame') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($isEdit)
                {{-- keep value submitted since disabled selects don't post --}}
                <input type="hidden" name="item_type" value="{{ $item->item_type }}">
            @endif
        </div>
        <div class="col-sm-6">
            <label for="brand" class="form-label small fw-medium mb-1">Brand</label>
            <input id="brand" name="brand" type="text" value="{{ $val('brand') }}"
                   class="form-control" placeholder="Ray-Ban / Oakley / Essilor">
        </div>
        <div class="col-12">
            <label for="model_name" class="form-label small fw-medium mb-1">Model / variant</label>
            <input id="model_name" name="model_name" type="text" value="{{ $val('model_name') }}"
                   class="form-control" placeholder="Aviator Classic">
        </div>
    </div>

    {{-- SKU + barcode --}}
    <div class="border border-2 border-dashed rounded-4 bg-light bg-opacity-50 p-3">
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-upc-scan text-primary"></i>
            <span class="fw-medium small">SKU &amp; Barcode</span>
        </div>
        @if ($isEdit)
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label small text-muted-foreground mb-1">SKU</label>
                    <input type="text" readonly value="{{ $item->sku }}" class="form-control font-monospace bg-white">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small text-muted-foreground mb-1">Barcode</label>
                    <input type="text" readonly value="{{ $item->barcode }}" class="form-control font-monospace bg-white">
                </div>
            </div>
            <div class="mt-3 text-center">
                <svg id="barcodePreview" data-barcode="{{ $item->barcode }}"></svg>
            </div>
        @else
            <p class="text-muted-foreground small mb-0">
                A unique SKU and a Code128 barcode are generated automatically when you save —
                printable on standard thermal printers.
            </p>
        @endif
    </div>

    {{-- Pricing & stock --}}
    <div class="row g-3">
        <div class="col-6 col-lg-3">
            <label for="cost_price" class="form-label small fw-medium mb-1">Cost price (₹) *</label>
            <input id="cost_price" name="cost_price" type="number" step="0.01" min="0" max="99999999" inputmode="decimal" required
                   value="{{ $val('cost_price') }}" class="form-control @error('cost_price') is-invalid @enderror">
        </div>
        <div class="col-6 col-lg-3">
            <label for="selling_price" class="form-label small fw-medium mb-1">Selling price (₹) *</label>
            <input id="selling_price" name="selling_price" type="number" step="0.01" min="0" max="99999999" inputmode="decimal" required
                   value="{{ $val('selling_price') }}" class="form-control @error('selling_price') is-invalid @enderror">
            <div id="marginWarning" class="d-none align-items-center gap-1 mt-1 text-warning" style="font-size:var(--text-xs);">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>Selling price is below cost — this item will sell at a loss.</span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <label for="stock_qty" class="form-label small fw-medium mb-1">Stock quantity</label>
            <input id="stock_qty" name="stock_qty" type="number" min="0" max="1000000" inputmode="numeric"
                   value="{{ $val('stock_qty', 0) }}" class="form-control">
        </div>
        <div class="col-6 col-lg-3">
            <label for="min_alert_qty" class="form-label small fw-medium mb-1">Low-stock threshold</label>
            <input id="min_alert_qty" name="min_alert_qty" type="number" min="0" max="1000000" inputmode="numeric"
                   value="{{ $val('min_alert_qty', 5) }}" class="form-control">
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 border-top pt-3">
        <a href="{{ route('tenant.inventory.index') }}" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Save changes' : 'Create item' }}</button>
    </div>
</form>

@if ($isEdit)
    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const el = document.getElementById('barcodePreview');
            if (el && el.dataset.barcode && window.JsBarcode) {
                JsBarcode(el, el.dataset.barcode, { format: 'CODE128', width: 2, height: 50, fontSize: 14 });
            }
        });
    </script>
    @endpush
@endif

@push('scripts')
<script>
    // NB-004: warn (don't block) when selling price drops below cost — clearance sales are allowed.
    document.addEventListener('DOMContentLoaded', () => {
        const cost = document.getElementById('cost_price');
        const sell = document.getElementById('selling_price');
        const warn = document.getElementById('marginWarning');
        if (!cost || !sell || !warn) return;
        const check = () => {
            const c = parseFloat(cost.value), s = parseFloat(sell.value);
            // 5.3: cost 0 = "not recorded" — nothing to compare, so no below-cost warning.
            const below = Number.isFinite(c) && c > 0 && Number.isFinite(s) && s < c;
            warn.classList.toggle('d-none', !below);
            warn.classList.toggle('d-flex', below);
        };
        cost.addEventListener('input', check);
        sell.addEventListener('input', check);
        check();
    });
</script>
@endpush

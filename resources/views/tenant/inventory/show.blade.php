@extends('layouts.app')
@section('title', 'View item')

@section('content')
<div class="p-4 p-md-5" style="max-width:54rem;">
    <a href="{{ route('tenant.inventory.index') }}"
       class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3" style="font-size:var(--text-sm);">
        <i class="bi bi-chevron-left"></i> Back to inventory
    </a>
    <div class="d-flex align-items-start justify-content-between gap-3">
        <div>
            <p class="section-label mb-1">View item</p>
            <h1 class="h3 fw-semibold font-display mb-1">{{ $item->brand }} {{ $item->model_name }}</h1>
            <p class="text-muted-foreground mb-4 font-monospace" style="font-size:var(--text-sm);">{{ $item->sku }}</p>
        </div>
        @if(auth()->user()->isStoreAdmin())
        <div>
            <a href="{{ route('tenant.inventory.edit', $item) }}" class="btn btn-primary">
                <i class="bi bi-pencil me-1"></i> Edit item
            </a>
        </div>
        @endif
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="d-flex flex-column gap-4">
                {{-- Identification --}}
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label small fw-medium mb-1">Item type</label>
                        <input type="text" readonly value="{{ ucfirst(str_replace('_', ' ', $item->item_type)) }}" class="form-control bg-light">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-medium mb-1">Brand</label>
                        <input type="text" readonly value="{{ $item->brand }}" class="form-control bg-light">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-medium mb-1">Model / variant</label>
                        <input type="text" readonly value="{{ $item->model_name }}" class="form-control bg-light">
                    </div>
                </div>

                {{-- SKU + barcode --}}
                <div class="border border-2 border-dashed rounded-4 bg-light bg-opacity-50 p-3">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-upc-scan text-primary"></i>
                        <span class="fw-medium small">SKU &amp; Barcode</span>
                    </div>
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
                </div>

                {{-- Pricing & stock --}}
                <div class="row g-3">
                    <div class="col-6 col-lg-3">
                        <label class="form-label small fw-medium mb-1">Cost price (₹)</label>
                        <input type="text" readonly value="{{ auth()->user()->isStoreAdmin() ? number_format($item->cost_price, 2) : 'XX.XX' }}" class="form-control bg-light text-muted">
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label small fw-medium mb-1">Selling price (₹)</label>
                        <input type="text" readonly value="{{ number_format($item->selling_price, 2) }}" class="form-control bg-light">
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label small fw-medium mb-1" class="{{ !$item->is_tracked ? 'text-muted' : '' }}">Stock quantity</label>
                        <input type="text" readonly value="{{ $item->is_tracked ? $item->stock_qty : 'Untracked' }}" class="form-control bg-light">
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label small fw-medium mb-1" class="{{ !$item->is_tracked ? 'text-muted' : '' }}">Low-stock threshold</label>
                        <input type="text" readonly value="{{ $item->is_tracked ? $item->min_alert_qty : 'N/A' }}" class="form-control bg-light">
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Barcode label (FT-Barcode) --}}
    <div class="card card-lift border-0 shadow-sm rounded-4 mt-4">
        <div class="card-body p-4">
            <div class="d-flex align-items-center justify-content-between mb-1">
                <p class="section-label mb-0">Barcode label</p>
                <div class="d-flex gap-2">
                    <button type="button" id="barcodeDownload" class="btn btn-light btn-sm">
                        <i class="bi bi-download me-1"></i> Download
                    </button>
                    <button type="button" id="barcodePrint" class="btn btn-light btn-sm">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                </div>
            </div>
            <p class="text-muted-foreground mb-3" style="font-size:var(--text-sm);">
                A Code128 label for shelf / item tagging. Saves and prints as
                <span class="font-monospace">{{ $item->sku }}</span>.
            </p>
            <div id="barcodeLabel" class="d-inline-flex flex-column align-items-center border rounded-3 p-3 bg-white">
                <div class="fw-medium mb-1" style="font-size:var(--text-sm);">{{ trim(($item->brand ?? '') . ' ' . $item->model_name) }}</div>
                <svg id="barcodeSvg" aria-label="Barcode for {{ $item->sku }}"></svg>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script nonce="{{ csp_nonce() }}" src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script nonce="{{ csp_nonce() }}">
    (function() {
        function init() {
            if (! window.JsBarcode) return;

            const value = @json($item->barcode);
            const sku = @json($item->sku);
            
            // Preview in form
            const preview = document.getElementById('barcodePreview');
            if (preview && preview.dataset.barcode) {
                JsBarcode(preview, preview.dataset.barcode, { format: 'CODE128', width: 2, height: 50, fontSize: 14 });
            }

            // Print label
            const svg = document.getElementById('barcodeSvg');
            if (svg) {
                const opts = { format: 'CODE128', displayValue: true, text: sku, fontSize: 14, height: 50, margin: 8 };
                JsBarcode(svg, value, opts);
            }

            // Sanitise the SKU for a safe download/print filename.
            const fileName = (sku || 'barcode').replace(/[^\w.-]+/g, '_');

            document.getElementById('barcodeDownload')?.addEventListener('click', () => {
                const opts = { format: 'CODE128', displayValue: true, text: sku, fontSize: 14, height: 50, margin: 8 };
                const canvas = document.createElement('canvas');
                JsBarcode(canvas, value, opts);
                const a = document.createElement('a');
                a.href = canvas.toDataURL('image/png');
                a.download = fileName + '.png';
                document.body.appendChild(a);
                a.click();
                a.remove();
            });

            document.getElementById('barcodePrint')?.addEventListener('click', () => {
                const label = document.getElementById('barcodeLabel');
                const w = window.open('', '_blank', 'width=420,height=320');
                if (! w) return;
                w.document.write(
                    '<html><head><title>' + fileName + '</title>' +
                    '<style>body{font-family:sans-serif;text-align:center;margin:16px;}</style>' +
                    '</head><body>' + label.innerHTML +
                    '<scr' + 'ipt>window.onload=function(){window.print();window.close();}</scr' + 'ipt>' +
                    '</body></html>'
                );
                w.document.close();
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    })();
</script>
@endpush
@endsection

@extends('layouts.app')
@section('title', 'Edit item')

@section('content')
<div class="p-4 p-md-5" style="max-width:54rem;">
    <a href="{{ route('tenant.inventory.index') }}"
       class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3" style="font-size:var(--text-sm);">
        <i class="bi bi-chevron-left"></i> Back to inventory
    </a>
    <div class="d-flex align-items-start justify-content-between gap-3">
        <div>
            <p class="section-label mb-1">Edit item</p>
            <h1 class="h3 fw-semibold font-display mb-1">{{ $item->brand }} {{ $item->model_name }}</h1>
            <p class="text-muted-foreground mb-4 font-monospace" style="font-size:var(--text-sm);">{{ $item->sku }}</p>
        </div>
        <div class="dropdown">
            <button class="btn btn-light" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions">
                <i class="bi bi-three-dots"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow rounded-3 border-0" style="box-shadow: var(--shadow-overlay);">
                <li>
                    <form method="POST" action="{{ route('tenant.inventory.destroy', $item) }}" class="m-0">
                        @csrf
                        @method('DELETE')
                        <button type="button" class="dropdown-item d-flex align-items-center gap-2 text-danger"
                                data-confirm="Archive {{ $item->brand }} {{ $item->model_name }}? The item is recoverable from the archive for 30 days."
                                data-confirm-title="Archive item"
                                data-confirm-label="Archive">
                            <i class="bi bi-archive"></i> Archive item
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            @include('tenant.inventory._form', ['mode' => 'edit', 'item' => $item])
        </div>
    </div>

    {{-- Barcode label (FT-Barcode) — Code128 for shelf/item tagging --}}
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

    {{-- Stock adjustment (FG-StockLog) --}}
    <div class="card card-lift border-0 shadow-sm rounded-4 mt-4">
        <div class="card-body p-4">
            <div class="d-flex align-items-center justify-content-between mb-1">
                <p class="section-label mb-0">Adjust stock</p>
                <span class="text-muted-foreground" style="font-size:var(--text-sm);">
                    Current: <span class="fw-semibold font-monospace">{{ $item->stock_qty }}</span>
                </span>
            </div>
            <p class="text-muted-foreground mb-3" style="font-size:var(--text-sm);">
                Record damage, loss, or a physical recount. Every change is logged below with a reason.
            </p>

            <form method="POST" action="{{ route('tenant.inventory.adjust', $item) }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-6 col-sm-3">
                    <label for="delta" class="form-label small fw-medium mb-2">Change (±)</label>
                    <input id="delta" type="number" name="delta" step="1" value="{{ old('delta') }}"
                           class="form-control @error('delta') is-invalid @enderror" placeholder="-1 or 5" required>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="reason" class="form-label small fw-medium mb-2">Reason</label>
                    <input id="reason" type="text" name="reason" value="{{ old('reason') }}" maxlength="255"
                           class="form-control @error('reason') is-invalid @enderror" placeholder="e.g. Damaged in transit" required>
                </div>
                <div class="col-6 col-sm-3">
                    <button type="submit" class="btn btn-secondary w-100">
                        <i class="bi bi-sliders me-1"></i> Apply
                    </button>
                </div>
                @error('delta')<div class="col-12"><div class="text-danger small">{{ $message }}</div></div>@enderror
                @error('reason')<div class="col-12"><div class="text-danger small">{{ $message }}</div></div>@enderror
            </form>
        </div>
    </div>

    {{-- Movement history --}}
    <div class="card card-lift border-0 shadow-sm rounded-4 mt-4">
        <div class="card-body p-4">
            <p class="section-label mb-3">Stock movement history</p>
            @if ($movements->isEmpty())
                <p class="text-muted-foreground mb-0" style="font-size:var(--text-sm);">No stock movements recorded yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0" style="font-size:var(--text-sm);">
                        <thead class="text-muted-foreground text-uppercase" style="font-size:var(--text-2xs);letter-spacing:.04em;">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Reason</th>
                                <th>By</th>
                                <th class="text-end">Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($movements as $m)
                                <tr>
                                    <td>{{ $m->created_at->format('d M Y, g:i A') }}</td>
                                    <td>{{ $m->type_label }}</td>
                                    <td class="text-muted-foreground">{{ $m->reason ?? '—' }}</td>
                                    <td class="text-muted-foreground">{{ $m->recorder?->name ?? '—' }}</td>
                                    <td class="text-end font-monospace fw-semibold {{ $m->delta < 0 ? 'text-danger' : 'text-success' }}">
                                        {{ $m->delta > 0 ? '+' : '' }}{{ $m->delta }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
    // FT-Barcode — render the item's barcode as Code128 (client-side JsBarcode) and
    // wire Download (PNG named by SKU) + Print. Guarded like the other deferred-ESM
    // consumers (BUG-001 / NB-002): wait for DOMContentLoaded, bail if the global
    // isn't present yet.
    (function () {
        function init() {
            if (! window.JsBarcode) return;

            const value = @json($item->barcode);
            const sku = @json($item->sku);
            const svg = document.getElementById('barcodeSvg');
            if (! svg) return;

            const opts = { format: 'CODE128', displayValue: true, text: sku, fontSize: 14, height: 50, margin: 8 };
            JsBarcode(svg, value, opts);

            // Sanitise the SKU for a safe download/print filename.
            const fileName = (sku || 'barcode').replace(/[^\w.-]+/g, '_');

            document.getElementById('barcodeDownload')?.addEventListener('click', () => {
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

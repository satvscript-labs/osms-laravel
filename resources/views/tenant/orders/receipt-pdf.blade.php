<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $num = strtoupper(substr($order->id, 0, 8));
        $rx = $order->eyeRecord;
        $p = $order->customer;
        $nz = fn ($v) => is_null($v) ? '—' : $v;
    @endphp
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1c2733; font-size: 12px; margin: 0; }
        .muted { color: #6b7785; }
        .small { font-size: 10px; }
        h1, h2 { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; padding-bottom: 16px; border-bottom: 1px solid #e2e6ec; }
        .box { border: 1px solid #e2e6ec; border-radius: 6px; padding: 10px; }
        .items th { text-align: left; border-bottom: 1px solid #e2e6ec; padding: 6px 4px; color: #6b7785; font-size: 10px; text-transform: uppercase; }
        .items td { padding: 6px 4px; border-bottom: 1px solid #f0f2f5; }
        .right { text-align: right; }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .totals td { padding: 3px 0; }
        .brand { width: 44px; height: 44px; background: #004f75; color: #fff; border-radius: 6px; text-align: center; font-size: 22px; line-height: 44px; }
    </style>
</head>
<body>
    {{-- Header --}}
    <table class="header">
        <tr>
            <td style="width:65%;">
                @if ($tenant?->logo_url && file_exists(public_path(parse_url($tenant->logo_url, PHP_URL_PATH))))
                    <img src="{{ public_path(parse_url($tenant->logo_url, PHP_URL_PATH)) }}" style="width:44px;height:44px;border-radius:6px;" alt="">
                @endif
                <h2 style="margin-top:6px;">{{ $tenant?->store_name ?? 'Optical Store' }}</h2>
                @if ($tenant?->address)<div class="muted small">{{ $tenant->address }}</div>@endif
                @if ($tenant?->tax_id)<div class="muted small">GSTIN: {{ $tenant->tax_id }}</div>@endif
            </td>
            <td class="right" style="width:35%;">
                <div class="muted small" style="text-transform:uppercase;">Receipt</div>
                <div class="mono" style="font-size:14px;">#{{ $num }}</div>
                <div class="muted small">{{ $order->created_at->format('d M Y') }}</div>
                @if ($order->needsPrep() && $order->estimated_ready_at)
                    <div class="small" style="margin-top:4px;">Estimated ready: <b>{{ $order->estimated_ready_at->format('d M Y') }}</b></div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Customer + Rx --}}
    <table style="margin-top:16px;">
        <tr>
            <td style="width:48%; vertical-align:top; padding-right:8px;">
                <div class="box">
                    <div class="muted small" style="text-transform:uppercase;">Customer</div>
                    <div style="font-weight:bold; font-size:14px;">{{ $p?->name }}</div>
                    @if ($p?->phone)<div class="muted small">{{ $p->phone }}</div>@endif
                    @if ($p?->age)<div class="muted small">{{ $p->age }} years · {{ $p->gender ?? '—' }}</div>@endif
                </div>
            </td>
            <td style="width:52%; vertical-align:top;">
                @if ($rx)
                    <div class="box">
                        <div class="muted small" style="text-transform:uppercase; margin-bottom:4px;">Prescription</div>
                        <table class="small">
                            <tr class="muted"><td></td><td>SPH</td><td>CYL</td><td>Axis</td><td>ADD</td><td>VA</td></tr>
                            <tr class="mono"><td><b>OD</b></td><td>{{ $nz($rx->od_sph) }}</td><td>{{ $nz($rx->od_cyl) }}</td><td>{{ $nz($rx->od_axis) }}</td><td>{{ $nz($rx->od_add) }}</td><td>{{ $rx->od_va ?? '—' }}</td></tr>
                            <tr class="mono"><td><b>OS</b></td><td>{{ $nz($rx->os_sph) }}</td><td>{{ $nz($rx->os_cyl) }}</td><td>{{ $nz($rx->os_axis) }}</td><td>{{ $nz($rx->os_add) }}</td><td>{{ $rx->os_va ?? '—' }}</td></tr>
                        </table>
                        @if (! is_null($rx->pd))<div class="small" style="margin-top:4px;">PD: <span class="mono">{{ $rx->pd }} mm</span></div>@endif
                    </div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Items --}}
    <div class="muted small" style="text-transform:uppercase; margin:16px 0 4px;">Items</div>
    <table class="items">
        <thead>
            <tr><th>Item</th><th>SKU</th><th class="right">Qty</th><th class="right">Unit</th><th class="right">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($order->items as $it)
                <tr>
                    <td>{{ $it->inventory?->brand ?? '—' }} {{ $it->inventory?->model_name }}</td>
                    <td class="mono small muted">{{ $it->inventory?->sku ?? '—' }}</td>
                    <td class="right">{{ $it->quantity }}</td>
                    <td class="right mono">{{ number_format($it->unit_price, 2) }}</td>
                    <td class="right mono">{{ number_format($it->unit_price * $it->quantity, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals --}}
    <table style="margin-top:16px;">
        <tr>
            <td style="width:60%;"></td>
            <td style="width:40%;">
                <table class="totals">
                    <tr><td class="muted">Subtotal</td><td class="right mono">₹ {{ number_format($order->subtotal, 2) }}</td></tr>
                    @if ($order->hasDiscount())
                        <tr><td class="muted">Discount ({{ $order->discount_label }})</td><td class="right mono">− ₹ {{ number_format($order->discount_amount, 2) }}</td></tr>
                    @endif
                    <tr><td class="muted">Total</td><td class="right mono">₹ {{ number_format($order->total_amount, 2) }}</td></tr>
                    <tr><td class="muted">Advance paid</td><td class="right mono">₹ {{ number_format($order->advance_paid, 2) }}</td></tr>
                    @if ($order->payments->isNotEmpty())
                        <tr><td class="muted">Paid via</td><td class="right">{{ $order->payments->pluck('method_label')->unique()->implode(', ') }}</td></tr>
                    @endif
                    <tr><td colspan="2"><hr style="border:none;border-top:1px solid #e2e6ec;"></td></tr>
                    <tr><td><b>Balance due</b></td><td class="right mono"><b>₹ {{ number_format($order->balance_due, 2) }}</b></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <p class="muted small" style="text-align:center; margin-top:24px; border-top:1px solid #e2e6ec; padding-top:10px;">
        Thank you for shopping with {{ $tenant?->store_name ?? 'us' }}. Please retain this receipt for any future visits.
    </p>
</body>
</html>

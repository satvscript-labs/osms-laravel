<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $num = strtoupper(substr($order->id, 0, 8));
        $p = $order->customer;
        $hasGst = $tenant?->hasGst() ?? false;
        $rate = $tenant?->effectiveGstRate() ?? 0.0;

        // Totals over ONLY the invoiced items — this is a partial-order document,
        // not a restatement of the full order (see receipt-pdf.blade.php for that).
        $grandTotal = 0.0;
        $taxableTotal = 0.0;
        $cgstTotal = 0.0;
        $sgstTotal = 0.0;
        $rows = $items->map(function ($it) use ($hasGst, $rate, &$grandTotal, &$taxableTotal, &$cgstTotal, &$sgstTotal) {
            $amount = (float) $it->unit_price * $it->quantity;
            $grandTotal += $amount;
            $split = $hasGst ? \App\Support\Gst::splitInclusive($amount, $rate) : null;
            if ($split) {
                $taxableTotal += $split['taxable'];
                $cgstTotal += $split['cgst'];
                $sgstTotal += $split['sgst'];
            }
            return ['item' => $it, 'amount' => $amount, 'split' => $split];
        });
    @endphp
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #4a3c0a; font-size: 12px; margin: 0; background: #fffdf5; }
        .muted { color: #8a7a3d; }
        .small { font-size: 10px; }
        h1, h2 { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; padding-bottom: 14px; border-bottom: 2px solid #eadfa0; }
        .box { border: 1px solid #eadfa0; background: #fdf6d8; border-radius: 6px; padding: 10px; }
        .items th { text-align: left; border-bottom: 1px solid #eadfa0; padding: 6px 4px; color: #8a6d1a; font-size: 10px; text-transform: uppercase; }
        .items td { padding: 6px 4px; border-bottom: 1px solid #f3ecc7; }
        .right { text-align: right; }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .totals td { padding: 3px 0; }
        .badge { display: inline-block; background: #8a6d1a; color: #fffdf5; font-size: 10px; font-weight: bold;
                 letter-spacing: 1px; padding: 4px 10px; border-radius: 4px; }
    </style>
</head>
<body>
    {{-- Header --}}
    <table class="header">
        <tr>
            <td style="width:60%;">
                <span class="badge">TAX INVOICE</span>
                <h2 style="margin-top:8px;">{{ $tenant?->store_name ?? 'Optical Store' }}</h2>
                @if ($tenant?->address)<div class="muted small">{{ $tenant->address }}</div>@endif
                @if ($hasGst)
                    <div class="small" style="margin-top:2px;"><b>GSTIN:</b> {{ $tenant->tax_id }}</div>
                @endif
            </td>
            <td class="right" style="width:40%;">
                <div class="muted small" style="text-transform:uppercase;">Invoice No.</div>
                <div class="mono" style="font-size:15px;font-weight:bold;">{{ $invoice->number }}</div>
                <div class="muted small" style="margin-top:4px;">Invoice date: {{ $invoice->created_at->format('d M Y') }}</div>
                <div class="muted small">Order ref: #{{ $num }}</div>
            </td>
        </tr>
    </table>

    {{-- Bill to --}}
    <table style="margin-top:16px;">
        <tr>
            <td style="vertical-align:top;">
                <div class="box">
                    <div class="muted small" style="text-transform:uppercase;">Bill to</div>
                    <div style="font-weight:bold; font-size:14px;">{{ $p?->name }}</div>
                    @if ($p?->phone)<div class="muted small">{{ $p->phone }}</div>@endif
                </div>
            </td>
        </tr>
    </table>

    {{-- Items --}}
    <div class="muted small" style="text-transform:uppercase; margin:16px 0 4px;">Items on this invoice</div>
    <table class="items">
        <thead>
            @if ($hasGst)
                <tr>
                    <th>Item</th><th class="right">Qty</th><th class="right">Rate (incl. tax)</th>
                    <th class="right">Taxable value</th><th class="right">CGST</th><th class="right">SGST</th>
                    <th class="right">Amount</th>
                </tr>
            @else
                <tr><th>Item</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Amount</th></tr>
            @endif
        </thead>
        <tbody>
            @foreach ($rows as $row)
                @php $it = $row['item']; @endphp
                <tr>
                    <td>
                        @if ($it->is_custom)
                            {{ $it->display_name }}
                        @else
                            {{ $it->inventory?->brand ?? '—' }} {{ $it->inventory?->model_name }}
                        @endif
                    </td>
                    <td class="right">{{ $it->quantity }}</td>
                    <td class="right mono">{{ number_format($it->unit_price, 2) }}</td>
                    @if ($hasGst)
                        <td class="right mono">{{ number_format($row['split']['taxable'], 2) }}</td>
                        <td class="right mono">{{ number_format($row['split']['cgst'], 2) }}</td>
                        <td class="right mono">{{ number_format($row['split']['sgst'], 2) }}</td>
                    @endif
                    <td class="right mono">{{ number_format($row['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals --}}
    <table style="margin-top:16px;">
        <tr>
            <td style="width:55%;"></td>
            <td style="width:45%;">
                <table class="totals">
                    @if ($hasGst)
                        <tr><td class="muted">Taxable value</td><td class="right mono">₹ {{ number_format($taxableTotal, 2) }}</td></tr>
                        <tr><td class="muted">CGST</td><td class="right mono">₹ {{ number_format($cgstTotal, 2) }}</td></tr>
                        <tr><td class="muted">SGST</td><td class="right mono">₹ {{ number_format($sgstTotal, 2) }}</td></tr>
                        <tr><td colspan="2"><hr style="border:none;border-top:1px solid #eadfa0;"></td></tr>
                    @endif
                    <tr><td><b>Total</b></td><td class="right mono"><b>₹ {{ number_format($grandTotal, 2) }}</b></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <p class="muted small" style="margin-top:20px;">
        @if ($hasGst)
            Prices shown are inclusive of applicable GST.
        @else
            This is a non-GST bill; no tax has been added or collected.
        @endif
        This invoice covers only the item(s) listed above — see your order receipt for the order's full total.
    </p>

    <p class="muted small" style="text-align:center; margin-top:20px; border-top:1px solid #eadfa0; padding-top:10px;">
        This is a digitally generated invoice and does not require a signature.<br>
        Thank you for shopping with {{ $tenant?->store_name ?? 'us' }}.
    </p>
</body>
</html>

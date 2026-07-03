@php $tax = $invoice->taxBreakdown(); @endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1c2733; font-size: 12px; margin: 0; padding: 32px; }
        h1 { font-size: 20px; margin: 0; color: #004f75; }
        .muted { color: #6b7785; }
        .row { width: 100%; }
        .row td { vertical-align: top; }
        .box { margin-top: 24px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 24px; }
        table.items th, table.items td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e3e8ee; }
        table.items th { background: #eef1f5; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        .right { text-align: right; }
        .totals { width: 45%; margin-left: 55%; margin-top: 16px; }
        .totals td { padding: 5px 10px; }
        .totals .grand { border-top: 2px solid #004f75; font-weight: bold; font-size: 14px; }
        .label { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #6b7785; }
        .foot { margin-top: 36px; font-size: 10px; color: #9aa5b1; text-align: center; }
    </style>
</head>
<body>
    <table class="row">
        <tr>
            <td style="width:60%;">
                <h1>OSMS</h1>
                <div class="muted" style="margin-top:4px;">{{ config('saas.legal_entity') }}</div>
                <div class="muted">{{ config('saas.contact_address') }}</div>
                <div class="muted">GSTIN: {{ config('saas.gst_number') }}</div>
            </td>
            <td style="width:40%;" class="right">
                <div style="font-size:18px; font-weight:bold;">TAX INVOICE</div>
                <div class="muted" style="margin-top:6px;">
                    No: {{ strtoupper(substr($invoice->id, 0, 8)) }}<br>
                    Date: {{ optional($invoice->paid_at ?? $invoice->created_at)->format('d M Y') }}
                </div>
            </td>
        </tr>
    </table>

    <div class="box">
        <div class="label">Billed to</div>
        <div style="margin-top:4px; font-weight:bold;">{{ $tenant->store_name }}</div>
        @if ($tenant->tax_id)<div class="muted">GSTIN: {{ $tenant->tax_id }}</div>@endif
        @if ($tenant->address)<div class="muted">{{ $tenant->address }}</div>@endif
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>OSMS subscription — monthly<br><span class="muted">Payment ref: {{ $invoice->razorpay_payment_id }}</span></td>
                <td class="right">₹ {{ number_format($tax['base'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted">Taxable value</td><td class="right">₹ {{ number_format($tax['base'], 2) }}</td></tr>
        <tr><td class="muted">CGST @ {{ $tax['rate'] / 2 }}%</td><td class="right">₹ {{ number_format($tax['cgst'], 2) }}</td></tr>
        <tr><td class="muted">SGST @ {{ $tax['rate'] / 2 }}%</td><td class="right">₹ {{ number_format($tax['sgst'], 2) }}</td></tr>
        <tr class="grand"><td>Total ({{ $invoice->currency }})</td><td class="right">₹ {{ number_format($tax['total'], 2) }}</td></tr>
    </table>

    <div class="foot">
        This is a computer-generated invoice and does not require a signature.
        Amounts are GST-inclusive. For questions, contact {{ config('saas.support_email') }}.
    </div>
</body>
</html>

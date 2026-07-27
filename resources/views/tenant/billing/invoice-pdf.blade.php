@php
    /**
     * BUG-P02 — this document is a TAX INVOICE only when the platform is actually
     * GST-registered. It previously always rendered an 18%-inclusive CGST/SGST
     * split beneath a blank GSTIN, asserting tax collected by an entity not
     * registered to collect it — on a document a customer might submit for input
     * tax credit.
     *
     * Not registered => a plain "Payment Receipt": one total, no tax lines, no
     * GSTIN. Flipping `saas.gst_registered` restores the compliant invoice with
     * no template change.
     */
    $isTaxInvoice = (bool) config('saas.gst_registered');
    $tax = $isTaxInvoice ? $invoice->taxBreakdown() : null;

    // BUG-P10 — never render a labelled-but-empty legal field. Blank values make
    // the whole line disappear rather than leaving "GSTIN:" hanging.
    $legalEntity = trim((string) config('saas.legal_entity'));
    $address     = trim((string) config('saas.contact_address'));
    $gstin       = trim((string) config('saas.gst_number'));
    $support     = trim((string) config('saas.support_email'));
@endphp
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
                @if ($legalEntity)<div class="muted" style="margin-top:4px;">{{ $legalEntity }}</div>@endif
                @if ($address)<div class="muted">{{ $address }}</div>@endif
                @if ($isTaxInvoice && $gstin)<div class="muted">GSTIN: {{ $gstin }}</div>@endif
            </td>
            <td style="width:40%;" class="right">
                <div style="font-size:18px; font-weight:bold;">
                    {{ $isTaxInvoice ? 'TAX INVOICE' : 'PAYMENT RECEIPT' }}
                </div>
                <div class="muted" style="margin-top:6px;">
                    No: {{ strtoupper(substr($invoice->id, 0, 8)) }}<br>
                    Date: {{ optional($invoice->paid_at ?? $invoice->created_at)->format('d M Y') }}
                </div>
            </td>
        </tr>
    </table>

    <div class="box">
        <div class="label">{{ $isTaxInvoice ? 'Billed to' : 'Received from' }}</div>
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
                <td>
                    OSMS subscription
                    @if ($invoice->razorpay_payment_id)
                        <br><span class="muted">Payment ref: {{ $invoice->razorpay_payment_id }}</span>
                    @endif
                </td>
                <td class="right">
                    ₹ {{ number_format($isTaxInvoice ? $tax['base'] : $invoice->amount, 2) }}
                </td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        @if ($isTaxInvoice)
            <tr><td class="muted">Taxable value</td><td class="right">₹ {{ number_format($tax['base'], 2) }}</td></tr>
            <tr><td class="muted">CGST @ {{ $tax['rate'] / 2 }}%</td><td class="right">₹ {{ number_format($tax['cgst'], 2) }}</td></tr>
            <tr><td class="muted">SGST @ {{ $tax['rate'] / 2 }}%</td><td class="right">₹ {{ number_format($tax['sgst'], 2) }}</td></tr>
            <tr class="grand"><td>Total ({{ $invoice->currency }})</td><td class="right">₹ {{ number_format($tax['total'], 2) }}</td></tr>
        @else
            {{-- No tax lines: none was charged. A single honest total. --}}
            <tr class="grand"><td>Total paid ({{ $invoice->currency }})</td><td class="right">₹ {{ number_format($invoice->amount, 2) }}</td></tr>
        @endif
    </table>

    <div class="foot">
        This is a computer-generated {{ $isTaxInvoice ? 'invoice' : 'receipt' }} and does not require a signature.
        @if ($isTaxInvoice) Amounts are GST-inclusive. @endif
        @if ($support) For questions, contact {{ $support }}. @endif
    </div>
</body>
</html>

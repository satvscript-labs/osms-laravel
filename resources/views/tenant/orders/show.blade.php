@extends('layouts.app')
@section('title', 'Order receipt')

@php
    $rx = $order->eyeRecord;
    $p = $order->customer;
    $num = strtoupper(substr($order->id, 0, 8));
    $nz = fn ($v) => is_null($v) ? '—' : $v;
    // An axis is degrees; 0 means "not recorded" (the range is 1..180).
    $axis = fn ($v) => is_null($v) || (int) $v === 0 ? '—' : (int) $v . '°';
    // Manual "Send on WhatsApp" pill for this order's current status (FT-WhatsApp).
    $pill = $waConfig->orderPill($order);
    // A pending automated message (still cancellable) supersedes the manual pill.
    $waPend = $waPending[$order->id] ?? null;
@endphp

@section('content')
<div>
    {{-- On-screen header (hidden in print) --}}
    <div class="p-4 p-md-5 pb-0 no-print">
        <a href="{{ route('tenant.orders.index') }}"
           class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3" style="font-size:var(--text-sm);">
            <i class="bi bi-chevron-left"></i> Back to orders
        </a>
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3">
            <div>
                <h1 class="h3 fw-semibold font-display mb-1">Order receipt</h1>
                <p class="text-muted-foreground mb-0" style="font-size:var(--text-sm);">
                    #{{ $num }} · {{ $order->created_at->format('d M Y') }}
                    · <i class="bi {{ $order->isInstant() ? 'bi-bag-check' : 'bi-clock-history' }} me-1"></i>{{ $order->fulfillment_label }}
                    @if ($order->needsPrep() && $order->estimated_ready_at)
                        · <span class="fw-medium">Ready {{ $order->estimated_ready_at->format('d M Y') }}</span>
                    @endif
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge text-capitalize {{ $order->isCancelled() ? 'text-bg-danger' : ($order->status === 'delivered' ? 'text-bg-light' : ($order->status === 'ready_for_pickup' ? 'text-bg-primary' : 'text-bg-secondary')) }}">
                    {{ $order->status_label }}
                </span>
                @unless ($order->isCancelled())
                    @if ($order->balance_due > 0)
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                            <i class="bi bi-cash-coin me-1"></i> Record payment
                        </button>
                    @endif
                    @if (in_array($order->status, ['pending', 'ready_for_pickup'], true))
                        <a href="{{ route('tenant.orders.edit', $order) }}" class="btn btn-light btn-sm">
                            <i class="bi bi-pencil me-1"></i> Edit
                        </a>
                    @endif
                    @if ($order->status !== 'delivered')
                        <button type="button" class="btn btn-light btn-sm text-danger" data-bs-toggle="modal" data-bs-target="#cancelOrderModal">
                            <i class="bi bi-x-circle me-1"></i> Cancel
                        </button>
                    @endif
                @endunless
                <a href="{{ route('tenant.orders.pdf', $order) }}" target="_blank" class="btn btn-light btn-sm">
                    <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                </a>
                @if ($taxInvoice)
                    <a href="{{ route('tenant.orders.tax-invoice.pdf', $order) }}" target="_blank" rel="noopener"
                       class="invoice-pill" aria-label="Formal tax invoice {{ $taxInvoice->number }}">
                        <i class="bi bi-receipt-cutoff"></i>
                        <span>Tax invoice</span>
                    </a>
                @endif
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
                @if ($p && $p->phone)
                    <a href="tel:{{ preg_replace('/[^\d+]/', '', $p->phone) }}" class="btn btn-secondary btn-sm text-decoration-none">
                        <i class="bi bi-telephone me-1"></i> Call
                    </a>
                @endif
                @if ($waPend)
                    @include('tenant.orders.partials._wa-undo', ['order' => $order, 'msg' => $waPend])
                @elseif ($pill)
                    <a href="{{ $pill['url'] }}" target="_blank" rel="noopener"
                       class="wa-pill {{ $pill['fallback'] ? 'wa-pill-warn' : '' }}"
                       aria-label="{{ $pill['label'] }}">
                        <i class="bi bi-whatsapp"></i>
                        <span>{{ $pill['label'] }}</span>
                    </a>
                @endif
            </div>
            @if (! $waPend && $pill && $pill['fallback'])
                <a href="{{ route('profile.edit') }}" class="wa-setup-hint mt-2 ms-auto no-print">
                    <i class="bi bi-exclamation-circle"></i> Finish WhatsApp setup to send automatically
                </a>
            @endif
        </div>

        @if ($order->isCancelled())
            <div class="alert alert-danger d-flex align-items-start gap-2 rounded-3 mt-3 mb-0" role="alert">
                <i class="bi bi-x-octagon-fill mt-1"></i>
                <div>
                    <div class="fw-semibold">Order cancelled{{ $order->cancelled_at ? ' on ' . $order->cancelled_at->format('d M Y') : '' }}</div>
                    <div class="small">Stock has been restored to inventory.@if ($order->cancel_reason) Reason: {{ $order->cancel_reason }}@endif</div>
                </div>
            </div>
        @endif
    </div>

    {{-- Receipt --}}
    <div class="mx-auto px-4 py-4 p-md-5" style="max-width:48rem;">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4 p-md-5">
                {{-- Store header --}}
                <div class="d-flex align-items-start justify-content-between gap-4 border-bottom pb-4 mb-4">
                    <div class="d-flex align-items-start gap-3">
                        @if ($tenant?->logo_url)
                            <img src="{{ $tenant->logo_url }}" alt="{{ $tenant->store_name }}"
                                 class="rounded-3 border object-fit-cover" style="width:3.5rem;height:3.5rem;">
                        @else
                            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white"
                                  style="width:3.5rem;height:3.5rem;"><i class="bi bi-eye fs-4"></i></span>
                        @endif
                        <div>
                            <h2 class="h5 fw-semibold font-display mb-1">{{ $tenant?->store_name ?? 'Optical Store' }}</h2>
                            @if ($tenant?->address)
                                <p class="text-muted-foreground mb-0" style="font-size:var(--text-xs);">
                                    <i class="bi bi-geo-alt me-1"></i>{{ $tenant->address }}
                                </p>
                            @endif
                            @if ($tenant?->tax_id)
                                <p class="text-muted-foreground mb-0" style="font-size:var(--text-xs);">GSTIN: {{ $tenant->tax_id }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="text-end">
                        <p class="text-uppercase text-muted-foreground mb-0" style="font-size:var(--text-3xs);letter-spacing:.05em;">Receipt</p>
                        <p class="font-monospace fw-medium mb-1">#{{ $num }}</p>
                        <p class="text-muted-foreground mb-0" style="font-size:var(--text-xs);">
                            <i class="bi bi-calendar3 me-1"></i>{{ $order->created_at->format('d M Y') }}
                        </p>
                    </div>
                </div>

                {{-- Customer + Rx --}}
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="border rounded-3 bg-light bg-opacity-50 p-3 h-100">
                            <p class="text-uppercase text-muted-foreground mb-1" style="font-size:var(--text-3xs);letter-spacing:.05em;">Customer</p>
                            <p class="fw-semibold font-display mb-1">{{ $p?->name }}</p>
                            <div class="text-muted-foreground" style="font-size:var(--text-xs);">
                                @if ($p?->phone)<p class="mb-0"><i class="bi bi-telephone me-1"></i>{{ $p->phone }}</p>@endif
                                @if ($p?->age)<p class="mb-0">{{ $p->age }} years · {{ $p->gender ?? '—' }}</p>@endif
                            </div>
                        </div>
                    </div>
                    {{-- 5.1 — prescription is confidential: shown to staff on screen, never printed on the customer receipt. --}}
                    @if ($rx)
                        <div class="col-md-6 no-print">
                            <div class="border rounded-3 bg-light bg-opacity-50 p-3 h-100">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <p class="text-uppercase text-muted-foreground mb-0" style="font-size:var(--text-3xs);letter-spacing:.05em;">Prescription</p>
                                    <span class="badge text-bg-light" style="font-size:var(--text-3xs);"><i class="bi bi-eye-slash me-1"></i>Staff only</span>
                                </div>
                                <table class="w-100" style="font-size:var(--text-xs);">
                                    <thead class="text-muted-foreground text-uppercase" style="font-size:var(--text-3xs);">
                                        <tr><th></th><th>SPH</th><th>CYL</th><th>Axis</th><th>ADD</th><th>VA</th></tr>
                                    </thead>
                                    <tbody class="font-monospace">
                                        <tr><td class="fw-semibold text-muted-foreground">OD</td>
                                            <td>{{ $nz($rx->od_sph) }}</td><td>{{ $nz($rx->od_cyl) }}</td>
                                            <td>{{ $axis($rx->od_axis) }}</td><td>{{ $nz($rx->od_add) }}</td><td>{{ $rx->od_va ?? '—' }}</td></tr>
                                        <tr><td class="fw-semibold text-muted-foreground">OS</td>
                                            <td>{{ $nz($rx->os_sph) }}</td><td>{{ $nz($rx->os_cyl) }}</td>
                                            <td>{{ $axis($rx->os_axis) }}</td><td>{{ $nz($rx->os_add) }}</td><td>{{ $rx->os_va ?? '—' }}</td></tr>
                                    </tbody>
                                </table>
                                @if (! is_null($rx->pd))
                                    <p class="mb-0 mt-2" style="font-size:var(--text-xs);">
                                        <span class="text-muted-foreground">PD:</span> <span class="font-monospace">{{ $rx->pd }} mm</span>
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Items --}}
                <p class="text-uppercase text-muted-foreground mb-2" style="font-size:var(--text-3xs);letter-spacing:.05em;">Items</p>
                <table class="table mb-4" style="font-size:var(--text-md);">
                    <thead class="text-muted-foreground" style="font-size:var(--text-xs);">
                        <tr><th>Item</th><th>SKU</th><th class="text-end">Qty</th><th class="text-end">Unit</th><th class="text-end">Total</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($order->items as $it)
                            <tr>
                                <td>
                                    @if ($it->is_custom)
                                        {{ $it->display_name }}
                                    @else
                                        {{ $it->inventory?->brand ?? '—' }} <span class="text-muted-foreground">{{ $it->inventory?->model_name }}</span>
                                    @endif
                                </td>
                                <td class="font-monospace text-muted-foreground" style="font-size:var(--text-xs);">{{ $it->inventory?->sku ?? '—' }}</td>
                                <td class="text-end">{{ $it->quantity }}</td>
                                <td class="text-end font-monospace">₹ {{ number_format($it->unit_price, 2) }}</td>
                                <td class="text-end font-monospace">₹ {{ number_format($it->unit_price * $it->quantity, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- Totals --}}
                <div class="ms-auto" style="max-width:18rem;">
                    <div class="d-flex justify-content-between mb-1" style="font-size:var(--text-md);">
                        <span class="text-muted-foreground">Subtotal</span>
                        <span class="font-monospace">₹ {{ number_format($order->subtotal, 2) }}</span>
                    </div>
                    @if ($order->hasDiscount())
                        <div class="d-flex justify-content-between mb-1" style="font-size:var(--text-md);">
                            <span class="text-muted-foreground">Discount <span class="text-faint">({{ $order->discount_label }})</span></span>
                            <span class="font-monospace text-success">− ₹ {{ number_format($order->discount_amount, 2) }}</span>
                        </div>
                    @endif
                    <div class="d-flex justify-content-between mb-1 fw-medium" style="font-size:var(--text-md);">
                        <span>Total</span>
                        <span class="font-monospace">₹ {{ number_format($order->total_amount, 2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2" style="font-size:var(--text-md);">
                        <span class="text-muted-foreground">Advance paid</span>
                        <span class="font-monospace">₹ {{ number_format($order->advance_paid, 2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between border-top pt-2 fw-semibold">
                        <span class="font-display">Balance due</span>
                        <span class="font-display {{ $order->balance_due > 0 ? 'text-danger' : '' }}">₹ {{ number_format($order->balance_due, 2) }}</span>
                    </div>
                </div>

                <p class="text-center text-muted-foreground border-top mt-4 pt-3 mb-0" style="font-size:var(--text-xs);">
                    Thank you for shopping with {{ $tenant?->store_name ?? 'us' }}. Please retain this receipt for any future visits.
                </p>
            </div>
        </div>

        {{-- Payment history (operational, not part of the printed receipt) --}}
        <div class="card card-lift border-0 shadow-sm rounded-4 mt-4 no-print">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <p class="section-label mb-0">Payment history</p>
                    @unless ($order->isCancelled())
                        @if ($order->balance_due > 0)
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                                <i class="bi bi-plus-lg me-1"></i> Record payment
                            </button>
                        @else
                            <span class="osms-badge osms-badge-green"><span class="osms-badge-dot"></span> Fully paid</span>
                        @endif
                    @endunless
                </div>

                @if ($order->payments->isEmpty())
                    <p class="text-muted-foreground mb-0" style="font-size:var(--text-sm);">No payments recorded yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" style="font-size:var(--text-sm);">
                            <thead class="text-muted-foreground text-uppercase" style="font-size:var(--text-2xs);letter-spacing:.04em;">
                                <tr>
                                    <th>Date</th>
                                    <th>Method</th>
                                    <th>Note</th>
                                    <th>By</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->payments as $payment)
                                    <tr>
                                        <td>{{ $payment->created_at->format('d M Y, g:i A') }}</td>
                                        <td>{{ $payment->method_label }}</td>
                                        <td class="text-muted-foreground">{{ $payment->note ?? '—' }}</td>
                                        <td class="text-muted-foreground">{{ $payment->recorder?->name ?? '—' }}</td>
                                        <td class="text-end font-monospace">₹ {{ number_format($payment->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-top">
                                    <td colspan="4" class="fw-semibold text-end">Total collected</td>
                                    <td class="text-end font-monospace fw-semibold">₹ {{ number_format($order->advance_paid, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Record-payment modal (FG-PaymentLog) --}}
@unless ($order->isCancelled())
    @if ($order->balance_due > 0)
        <div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-labelledby="recordPaymentTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-4 border-0" style="box-shadow: var(--shadow-overlay);">
                    <form method="POST" action="{{ route('tenant.orders.payments.store', $order) }}">
                        @csrf
                        <div class="modal-header border-0 pb-0">
                            <h2 class="h5 fw-semibold font-display mb-0" id="recordPaymentTitle">Record a payment</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-4 d-flex flex-column gap-3">
                            <div class="rounded-3 p-3" style="background: var(--surface-sunken);">
                                <div class="d-flex justify-content-between" style="font-size:var(--text-sm);">
                                    <span class="text-muted-foreground">Balance due</span>
                                    <span class="font-monospace fw-semibold text-danger">₹ {{ number_format($order->balance_due, 2) }}</span>
                                </div>
                            </div>
                            <div>
                                <label for="pay_amount" class="form-label small fw-medium mb-2">Amount (₹)</label>
                                <input id="pay_amount" type="number" name="amount" step="0.01" min="0.01"
                                       max="{{ $order->balance_due }}" value="{{ $order->balance_due }}"
                                       class="form-control" required>
                                <div class="form-text" style="font-size:var(--text-xs);">Anything above the balance is automatically capped.</div>
                            </div>
                            <div>
                                <label for="pay_method" class="form-label small fw-medium mb-2">Method</label>
                                <select id="pay_method" name="method" class="form-select" required>
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="upi">UPI</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label for="pay_note" class="form-label small fw-medium mb-2">Note <span class="text-muted-foreground">(optional)</span></label>
                                <input id="pay_note" type="text" name="note" maxlength="255" class="form-control" placeholder="e.g. Balance on delivery">
                            </div>
                        </div>
                        <div class="modal-footer border-0 pt-0">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Record payment</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- Cancel-order modal (NB-009) --}}
    @if ($order->status !== 'delivered')
        <div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-labelledby="cancelOrderTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-4 border-0" style="box-shadow: var(--shadow-overlay);">
                    <form method="POST" action="{{ route('tenant.orders.cancel', $order) }}">
                        @csrf
                        <div class="modal-body p-4 text-center">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                                  style="width:3rem;height:3rem;background:var(--tone-red-bg);color:var(--tone-red);">
                                <i class="bi bi-x-octagon fs-4"></i>
                            </span>
                            <h2 class="h5 fw-semibold font-display mb-2" id="cancelOrderTitle">Cancel this order?</h2>
                            <p class="text-muted-foreground mb-3">The stock reserved by this order will be returned to inventory. This cannot be undone.</p>
                            <div class="text-start mb-2">
                                <label for="cancel_reason" class="form-label small fw-medium mb-2">Reason <span class="text-muted-foreground">(optional)</span></label>
                                <input id="cancel_reason" type="text" name="cancel_reason" maxlength="255" class="form-control" placeholder="e.g. Customer changed their mind">
                            </div>
                        </div>
                        <div class="modal-footer border-0 pt-0 justify-content-center">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep order</button>
                            <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i> Cancel order &amp; restore stock</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endunless
@endsection

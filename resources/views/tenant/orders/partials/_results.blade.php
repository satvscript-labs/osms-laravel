@php
    // Status presentation: badge tone class + human label. The dot inherits its
    // colour from the tone class via `currentColor` (UX-02 — no hardcoded hex).
    $statusMeta = [
        'pending'          => ['label' => 'In lab',    'badge' => 'osms-badge-amber'],
        'ready_for_pickup' => ['label' => 'Ready',     'badge' => 'osms-badge-blue'],
        'delivered'        => ['label' => 'Delivered', 'badge' => 'osms-badge-green'],
        'cancelled'        => ['label' => 'Cancelled', 'badge' => 'osms-badge-red'],
    ];

    // Sort header link that flips direction on the active column. Marked with
    // data-sortlink so the live layer intercepts it (fetch-swap, no reload).
    $sortLink = function ($key, $label) use ($sort, $dir) {
        $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
        $icon = $sort !== $key ? 'bi-arrow-down-up opacity-25'
              : ($dir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down');
        $params = array_merge(request()->query(), ['sort' => $key, 'dir' => $nextDir]);
        unset($params['partial']);
        $url = route('tenant.orders.index') . '?' . http_build_query($params);
        return '<a href="' . $url . '" data-sortlink class="text-reset text-decoration-none d-inline-flex align-items-center gap-1">'
             . e($label) . ' <i class="bi ' . $icon . '" style="font-size:var(--text-2xs);"></i></a>';
    };
@endphp

@if ($orders->isNotEmpty())
    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0 osms-orders-table">
                <thead class="text-muted-foreground text-uppercase text-xs" style="letter-spacing:.04em;">
                    <tr>
                        <th class="ps-4">Order / Customer</th>
                        <th>Status</th>
                        <th class="text-end">{!! $sortLink('total_amount', 'Total') !!}</th>
                        <th class="text-end">{!! $sortLink('balance_due', 'Balance') !!}</th>
                        <th class="text-center d-none d-lg-table-cell">Items</th>
                        <th class="d-none d-md-table-cell">{!! $sortLink('created_at', 'Placed') !!}</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        @php
                            $meta = $statusMeta[$order->status] ?? ['label' => $order->status_label, 'badge' => 'osms-badge-neutral'];
                            $num = strtoupper(substr($order->id, 0, 8));
                            $next = $order->status === 'pending' ? 'ready_for_pickup'
                                  : ($order->status === 'ready_for_pickup' ? 'delivered' : null);
                            $nextLabel = $order->status === 'pending' ? 'Mark ready' : 'Deliver';

                            $phone = $order->customer?->phone;
                            // Mode-aware manual WhatsApp pill (FT-WhatsApp); null when Off /
                            // Automated / no phone / event disabled.
                            $pill = $waConfig->orderPill($order);

                            $showReady = $order->needsPrep() && $order->estimated_ready_at
                                && ! in_array($order->status, ['delivered', 'cancelled'], true);
                            $overdue = $showReady && $order->estimated_ready_at->startOfDay()->lt(today());
                        @endphp
                        <tr role="button" onclick="window.location='{{ route('tenant.orders.show', $order) }}'">
                            <td class="ps-4">
                                <div class="fw-semibold text-truncate" style="max-width:14rem;">
                                    {{ $order->customer?->name ?? 'Unknown customer' }}
                                </div>
                                <div class="text-muted-foreground d-flex align-items-center gap-2 flex-wrap text-xs">
                                    <span class="font-monospace">#{{ $num }}</span>
                                    @if ($phone)
                                        <span><i class="bi bi-telephone me-1"></i>{{ $phone }}</span>
                                    @endif
                                    @if ($order->isInstant())
                                        <span class="meta-chip"><i class="bi bi-lightning-charge-fill"></i> Instant</span>
                                    @elseif ($showReady)
                                        <span class="meta-chip {{ $overdue ? 'meta-chip-danger' : '' }}">
                                            <i class="bi bi-clock"></i> Ready {{ $order->estimated_ready_at->format('d M') }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="osms-badge {{ $meta['badge'] }}">
                                    <span class="osms-badge-dot"></span>
                                    {{ $meta['label'] }}
                                </span>
                            </td>
                            <td class="text-end font-monospace">₹{{ number_format($order->total_amount, 0) }}</td>
                            <td class="text-end font-monospace">
                                @if ($order->balance_due > 0)
                                    <span class="text-danger fw-medium">₹{{ number_format($order->balance_due, 0) }}</span>
                                @else
                                    <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Paid</span>
                                @endif
                            </td>
                            <td class="text-center d-none d-lg-table-cell text-muted-foreground">{{ $order->items_count }}</td>
                            <td class="d-none d-md-table-cell text-muted-foreground text-sm">
                                {{ $order->created_at->format('d M Y') }}
                            </td>
                            <td class="pe-4" onclick="event.stopPropagation()">
                                @php $waPend = $waPending[$order->id] ?? null; @endphp
                                <div class="d-flex align-items-center justify-content-end gap-1">
                                    @if ($waPend)
                                        @include('tenant.orders.partials._wa-undo', ['order' => $order, 'msg' => $waPend, 'variant' => 'compact'])
                                    @else
                                        @if ($next)
                                            <button type="button" class="advance-liquid advance-liquid-sm advance-btn"
                                                    data-id="{{ $order->id }}" data-next="{{ $next }}"
                                                    @if ($next === 'delivered')
                                                        data-settle="1"
                                                        data-customer="{{ $order->customer?->name ?? 'Customer' }}"
                                                        data-subtotal="{{ $order->subtotal }}"
                                                        data-total="{{ $order->total_amount }}"
                                                        data-advance="{{ $order->advance_paid }}"
                                                        data-balance="{{ $order->balance_due }}"
                                                        data-discount-type="{{ $order->discount_type }}"
                                                        data-discount-value="{{ $order->discount_value }}"
                                                    @endif>
                                                <span class="spinner-border spinner-border-sm d-none me-1" role="status"></span>
                                                {{ $nextLabel }}<i class="bi bi-arrow-right ms-1"></i>
                                            </button>
                                        @endif
                                        @if ($pill)
                                            <a href="{{ $pill['url'] }}" target="_blank" rel="noopener"
                                               class="wa-pill wa-pill-icon {{ $pill['fallback'] ? 'wa-pill-warn' : '' }}"
                                               title="{{ $pill['label'] }}{{ $pill['fallback'] ? ' — finish WhatsApp setup' : '' }}"
                                               aria-label="{{ $pill['label'] }}">
                                                <i class="bi bi-whatsapp"></i>
                                            </a>
                                        @endif
                                        @if ($phone)
                                            <a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}" class="action-icon-btn" title="Call customer" aria-label="Call customer">
                                                <i class="bi bi-telephone"></i>
                                            </a>
                                        @endif
                                    @endif
                                    <a href="{{ route('tenant.orders.pdf', $order) }}" target="_blank"
                                       class="action-icon-btn" title="PDF receipt" aria-label="PDF receipt">
                                        <i class="bi bi-file-earmark-pdf"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2 mt-3">
        <p class="text-muted-foreground mb-0 text-sm">
            Showing {{ $orders->firstItem() }}–{{ $orders->lastItem() }} of {{ number_format($orders->total()) }} orders
        </p>
        @if ($orders->hasPages())
            <div>{{ $orders->links() }}</div>
        @endif
    </div>
@else
    <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
        <span class="d-inline-flex align-items-center justify-content-center rounded-4 bg-primary-subtle text-primary mb-3"
              style="width:3rem;height:3rem;"><i class="bi bi-bag fs-4"></i></span>
        <h2 class="h5 fw-semibold font-display">
            {{ ($search || $status || $payment) ? 'No orders match your filters' : 'No orders yet' }}
        </h2>
        <p class="text-muted-foreground mb-3">
            {{ ($search || $status || $payment) ? 'Try adjusting your search, status, or payment filters.' : 'Create your first order to start tracking the lab workflow.' }}
        </p>
        @if ($search || $status || $payment)
            <a href="{{ route('tenant.orders.index') }}" class="btn btn-secondary"><i class="bi bi-x-lg me-1"></i> Clear filters</a>
        @else
            <a href="{{ route('tenant.orders.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> New order</a>
        @endif
    </div>
@endif

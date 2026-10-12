@php
    $columns = [
        ['key' => 'pending',          'title' => 'In lab',           'desc' => 'Being prepared',      'icon' => 'bi-clock-history'],
        ['key' => 'ready_for_pickup', 'title' => 'Ready for pickup', 'desc' => 'Waiting for customer','icon' => 'bi-bag-check'],
        ['key' => 'delivered',        'title' => 'Delivered',        'desc' => 'Completed · last 7 days', 'icon' => 'bi-check-circle'],
    ];
@endphp

<p class="text-muted-foreground mb-3 text-sm">
    <i class="bi bi-arrows-move me-1"></i> Drag a card between columns, or use the button to advance an order.
</p>

<div class="row g-3">
    @foreach ($columns as $col)
        @php $colOrders = $orders[$col['key']] ?? collect(); @endphp
        <div class="col-md-4">
            <div class="d-flex align-items-center justify-content-between px-1 mb-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary"
                          style="width:1.75rem;height:1.75rem;"><i class="bi {{ $col['icon'] }}"></i></span>
                    <div>
                        <p class="mb-0 fw-semibold font-display text-md">{{ $col['title'] }}</p>
                        <p class="mb-0 text-muted-foreground text-2xs">{{ $col['desc'] }}</p>
                    </div>
                </div>
                <span class="badge text-bg-light">{{ $colOrders->count() }}</span>
            </div>

            <div class="kanban-column stagger" data-status="{{ $col['key'] }}">
                @forelse ($colOrders as $order)
                    @php
                        $phone = $order->customer?->phone;
                        // Mode-aware manual "Send on WhatsApp" pill (FT-WhatsApp); null when
                        // Off / Automated / no phone / event disabled.
                        $pill = $waConfig->orderPill($order);
                    @endphp
                    <div class="kanban-card card border-0 shadow-sm rounded-3 mb-2" data-id="{{ $order->id }}"
                         data-customer="{{ $order->customer?->name ?? 'Customer' }}"
                         data-subtotal="{{ $order->subtotal }}"
                         data-total="{{ $order->total_amount }}"
                         data-advance="{{ $order->advance_paid }}"
                         data-balance="{{ $order->balance_due }}"
                         data-discount-type="{{ $order->discount_type }}"
                         data-discount-value="{{ $order->discount_value }}">
                        <div class="card-body p-3">
                            <a href="{{ route('tenant.orders.show', $order) }}" class="text-decoration-none text-reset d-block">
                                <p class="mb-0 fw-medium text-truncate">{{ $order->customer?->name ?? 'Unknown customer' }}</p>
                                @if ($phone)
                                    <p class="mb-2 text-muted-foreground text-xs">
                                        <i class="bi bi-telephone me-1"></i>{{ $phone }}
                                    </p>
                                @endif
                                <div class="row g-1 text-center text-xs">
                                    <div class="col-4">
                                        <div class="text-muted-foreground text-uppercase text-3xs">Total</div>
                                        <div class="font-monospace">₹{{ number_format($order->total_amount, 0) }}</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-muted-foreground text-uppercase text-3xs">Balance</div>
                                        <div class="font-monospace {{ $order->balance_due > 0 ? 'text-danger' : '' }}">₹{{ number_format($order->balance_due, 0) }}</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-muted-foreground text-uppercase text-3xs">Items</div>
                                        <div class="font-monospace">{{ $order->items_count }}</div>
                                    </div>
                                </div>
                                <p class="mb-0 mt-2 text-muted-foreground text-2xs">
                                    Placed {{ $order->created_at->format('d M Y') }}
                                </p>
                                @if ($col['key'] !== 'delivered' && $order->needsPrep() && $order->estimated_ready_at)
                                    @php $overdue = $order->estimated_ready_at->startOfDay()->lt(today()); @endphp
                                    <span class="meta-chip mt-2 {{ $overdue ? 'meta-chip-danger' : '' }}">
                                        <i class="bi bi-clock"></i> Ready {{ $order->estimated_ready_at->format('d M') }}
                                    </span>
                                @endif
                            </a>

                            {{-- Actions --}}
                            @php $waPend = $waPending[$order->id] ?? null; @endphp
                            @if ($waPend)
                                {{-- Automated message counting down — offer the undo ring instead. --}}
                                @include('tenant.orders.partials._wa-undo', ['order' => $order, 'msg' => $waPend, 'variant' => 'block'])
                            @else
                                @if ($col['key'] !== 'delivered')
                                    @php $next = $col['key'] === 'pending' ? 'ready_for_pickup' : 'delivered'; @endphp
                                    <div class="d-flex align-items-center gap-1 mt-2">
                                        <button type="button" class="advance-liquid flex-grow-1 advance-btn"
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
                                            {{ $col['key'] === 'pending' ? 'Mark ready' : 'Mark delivered' }}
                                            <i class="bi bi-arrow-right ms-1"></i>
                                        </button>
                                    </div>
                                @endif

                                {{-- Manual "Send on WhatsApp" pill and Call button. --}}
                                @if ($pill || $order->customer?->phone)
                                    <div class="d-flex gap-2 mt-2">
                                        @if ($pill)
                                            <a href="{{ $pill['url'] }}" target="_blank" rel="noopener"
                                               class="wa-pill w-100 {{ $pill['fallback'] ? 'wa-pill-warn' : '' }}"
                                               aria-label="{{ $pill['label'] }}">
                                                <i class="bi bi-whatsapp"></i>
                                                <span>{{ $pill['label'] }}</span>
                                            </a>
                                        @endif
                                        @if ($order->customer?->phone)
                                            <a href="tel:{{ preg_replace('/[^\d+]/', '', $order->customer->phone) }}" class="call-pill wa-pill-icon" title="Call customer" aria-label="Call customer">
                                                <i class="bi bi-telephone-fill"></i> <span>Call</span>
                                            </a>
                                        @endif
                                    </div>
                                    @if ($pill && $pill['fallback'])
                                        <a href="{{ route('profile.edit') }}" class="wa-setup-hint mt-1">
                                            <i class="bi bi-exclamation-circle"></i> Finish WhatsApp setup
                                        </a>
                                    @endif
                                @endif
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-center text-muted-foreground border border-2 border-dashed rounded-3 py-4 mb-0 text-sm">Nothing here yet</p>
                @endforelse
            </div>
        </div>
    @endforeach
</div>

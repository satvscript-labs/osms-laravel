@php
    $columns = [
        ['key' => 'pending',          'title' => 'In lab',           'desc' => 'Being prepared',      'icon' => 'bi-clock-history'],
        ['key' => 'ready_for_pickup', 'title' => 'Ready for pickup', 'desc' => 'Waiting for customer','icon' => 'bi-bag-check'],
        ['key' => 'delivered',        'title' => 'Delivered',        'desc' => 'Completed',           'icon' => 'bi-check-circle'],
    ];
@endphp

<p class="text-muted-foreground mb-3" style="font-size:.82rem;">
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
                        <p class="mb-0 fw-semibold font-display" style="font-size:.9rem;">{{ $col['title'] }}</p>
                        <p class="mb-0 text-muted-foreground" style="font-size:.7rem;">{{ $col['desc'] }}</p>
                    </div>
                </div>
                <span class="badge text-bg-light">{{ $colOrders->count() }}</span>
            </div>

            <div class="kanban-column" data-status="{{ $col['key'] }}">
                @forelse ($colOrders as $order)
                    <div class="kanban-card card border-0 shadow-sm rounded-3 mb-2" data-id="{{ $order->id }}">
                        <div class="card-body p-3">
                            <a href="{{ route('tenant.orders.show', $order) }}" class="text-decoration-none text-reset d-block">
                                <p class="mb-0 fw-medium text-truncate">{{ $order->customer?->name ?? 'Unknown customer' }}</p>
                                @if ($order->customer?->phone)
                                    <p class="mb-2 text-muted-foreground" style="font-size:.72rem;">
                                        <i class="bi bi-telephone me-1"></i>{{ $order->customer->phone }}
                                    </p>
                                @endif
                                <div class="row g-1 text-center" style="font-size:.72rem;">
                                    <div class="col-4">
                                        <div class="text-muted-foreground text-uppercase" style="font-size:.6rem;">Total</div>
                                        <div class="font-monospace">₹{{ number_format($order->total_amount, 0) }}</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-muted-foreground text-uppercase" style="font-size:.6rem;">Balance</div>
                                        <div class="font-monospace {{ $order->balance_due > 0 ? 'text-danger' : '' }}">₹{{ number_format($order->balance_due, 0) }}</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-muted-foreground text-uppercase" style="font-size:.6rem;">Items</div>
                                        <div class="font-monospace">{{ $order->items_count }}</div>
                                    </div>
                                </div>
                                <p class="mb-0 mt-2 text-muted-foreground" style="font-size:.68rem;">
                                    Placed {{ $order->created_at->format('d M Y') }}
                                </p>
                                @if ($col['key'] !== 'delivered' && $order->needsPrep() && $order->estimated_ready_at)
                                    @php $overdue = $order->estimated_ready_at->startOfDay()->lt(today()); @endphp
                                    <span class="badge mt-2 {{ $overdue ? 'text-bg-danger' : 'text-bg-light' }}" style="font-size:.66rem;">
                                        <i class="bi bi-clock me-1"></i>Ready {{ $order->estimated_ready_at->format('d M') }}
                                    </span>
                                @endif
                            </a>
                            @if ($col['key'] !== 'delivered')
                                @php $next = $col['key'] === 'pending' ? 'ready_for_pickup' : 'delivered'; @endphp
                                <button type="button" class="btn btn-primary btn-sm w-100 mt-2 advance-btn"
                                        data-id="{{ $order->id }}" data-next="{{ $next }}">
                                    <span class="spinner-border spinner-border-sm d-none me-1" role="status"></span>
                                    {{ $col['key'] === 'pending' ? 'Mark ready' : 'Mark delivered' }}
                                    <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-center text-muted-foreground border border-2 border-dashed rounded-3 py-4 mb-0"
                       style="font-size:.8rem;">Nothing here yet</p>
                @endforelse
            </div>
        </div>
    @endforeach
</div>

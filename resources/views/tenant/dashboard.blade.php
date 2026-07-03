@extends('layouts.app')
@section('title', 'Dashboard')

@php
    $first = \Illuminate\Support\Str::of(auth()->user()->name)->before(' ')->value();
    $hour = now()->hour;
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $attentionCount = ($subscriptionPastDue ? 1 : 0) + $dueToPrepare->count() + $overduePickups->count() + $lowStockCount;
@endphp

@section('content')
<div class="p-4 p-md-5">
    {{-- Header --}}
    <div class="d-flex flex-column flex-md-row gap-3 align-items-md-end justify-content-between mb-4">
        <div>
            <p class="section-label mb-1">{{ now()->format('l, d M Y') }}</p>
            <h1 class="h3 fw-semibold font-display mb-1">
                {{ $first ? "$greeting, $first" : $greeting }}
            </h1>
            <p class="text-muted-foreground mb-0 text-md">Here's how your store is doing today.</p>
        </div>
        <a href="{{ route('tenant.orders.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i> New order
        </a>
    </div>

    {{-- Metrics --}}
    <div class="row g-3 mb-4 stagger">
        <div class="col-6 col-lg-3">
            <x-metric-card label="Today's sales" value="₹ {{ number_format($todaySales, 0) }}"
                           hint="Delivered orders" icon="bi-currency-rupee" tone="primary" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Pending orders" value="{{ $pendingCount }}"
                           hint="In the lab" icon="bi-clock-history"
                           :href="safe_route('tenant.orders.index', ['status' => 'pending'])" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Ready for pickup" value="{{ $readyCount }}"
                           hint="Awaiting customer" icon="bi-bag-check"
                           :href="safe_route('tenant.orders.index', ['status' => 'ready_for_pickup'])"
                           :tone="$readyCount > 0 ? 'primary' : 'default'" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Low stock" value="{{ $lowStockCount }}"
                           hint="{{ $lowStockCount > 0 ? 'Needs attention' : 'All good' }}" icon="bi-exclamation-triangle"
                           :href="safe_route('tenant.inventory.index', ['stock' => 'low'])"
                           :tone="$lowStockCount > 0 ? 'amber' : 'default'" />
        </div>
    </div>

    <div class="row g-4">
        {{-- Quick actions --}}
        <div class="col-lg-8">
            <h2 class="section-label mb-3">Quick actions</h2>
            <div class="row g-3 stagger">
                @php
                    $actions = [
                        ['route' => 'tenant.orders.create', 'label' => 'New order', 'desc' => 'Create a POS estimate', 'icon' => 'bi-cart-plus'],
                        ['route' => 'tenant.customers.create', 'label' => 'New customer', 'desc' => 'Register a customer', 'icon' => 'bi-person-plus'],
                        ['route' => 'tenant.inventory.create', 'label' => 'Add stock', 'desc' => 'New frame or lens', 'icon' => 'bi-plus-square'],
                        ['route' => 'tenant.inventory.index', 'params' => ['scan' => 1], 'label' => 'Scan barcode', 'desc' => 'Look up an item', 'icon' => 'bi-upc-scan'],
                    ];
                @endphp
                @foreach ($actions as $a)
                    <div class="col-sm-6">
                        <a href="{{ safe_route($a['route'], $a['params'] ?? []) }}"
                           class="card card-lift border-0 shadow-sm rounded-4 text-decoration-none text-reset h-100">
                            <div class="card-body d-flex align-items-center gap-3 p-3">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary"
                                      style="width:2.6rem;height:2.6rem;"><i class="bi {{ $a['icon'] }} fs-5"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    <p class="mb-0 fw-medium text-md">{{ $a['label'] }}</p>
                                    <p class="mb-0 text-muted-foreground text-xs">{{ $a['desc'] }}</p>
                                </div>
                                <i class="bi bi-chevron-right quick-action-arrow"></i>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Alerts --}}
        <div class="col-lg-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <h2 class="section-label mb-0">Needs attention</h2>
                @if ($attentionCount > 0)
                    <span class="osms-badge osms-badge-red">{{ $attentionCount }}</span>
                @endif
            </div>
            <div class="card border-0 shadow-sm rounded-4">
                <div class="list-group list-group-flush rounded-4 stagger">
                    @if ($subscriptionPastDue)
                        <a href="{{ safe_route('tenant.billing.index') }}"
                           class="list-group-item list-group-item-action d-flex gap-3 align-items-center py-3">
                            <span class="alert-icon alert-icon-red"><i class="bi bi-credit-card"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <p class="mb-0 fw-medium text-sm text-truncate">Subscription past due</p>
                                <p class="mb-0 text-muted-foreground text-xs">Renew to avoid interruption</p>
                            </div>
                            <i class="bi bi-chevron-right text-faint"></i>
                        </a>
                    @endif

                    @foreach ($dueToPrepare as $o)
                        <a href="{{ safe_route('tenant.orders.show', $o['id']) }}"
                           class="list-group-item list-group-item-action d-flex gap-3 align-items-center py-3">
                            <span class="alert-icon {{ $o['overdue_days'] > 0 ? 'alert-icon-red' : 'alert-icon-blue' }}"><i class="bi bi-tools"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <p class="mb-0 fw-medium text-sm text-truncate">{{ $o['customer_name'] ?? 'Walk-in' }}</p>
                                <p class="mb-0 text-muted-foreground text-xs">
                                    @if ($o['overdue_days'] > 0)
                                        {{ $o['overdue_days'] }} day{{ $o['overdue_days'] == 1 ? '' : 's' }} overdue to prepare
                                    @else
                                        Due to prepare today
                                    @endif
                                </p>
                            </div>
                            <span class="meta-chip">₹{{ number_format($o['total_amount'], 0) }}</span>
                        </a>
                    @endforeach

                    @foreach ($overduePickups as $o)
                        <a href="{{ safe_route('tenant.orders.show', $o['id']) }}"
                           class="list-group-item list-group-item-action d-flex gap-3 align-items-center py-3">
                            <span class="alert-icon alert-icon-amber"><i class="bi bi-hourglass-split"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <p class="mb-0 fw-medium text-sm text-truncate">{{ $o['customer_name'] ?? 'Walk-in' }}</p>
                                <p class="mb-0 text-muted-foreground text-xs">
                                    Ready {{ $o['days'] }} day{{ $o['days'] == 1 ? '' : 's' }} — uncollected
                                </p>
                            </div>
                            <span class="meta-chip">₹{{ number_format($o['total_amount'], 0) }}</span>
                        </a>
                    @endforeach

                    @forelse ($lowStock as $item)
                        <a href="{{ safe_route('tenant.inventory.edit', $item->id) }}"
                           class="list-group-item list-group-item-action d-flex gap-3 align-items-center py-3">
                            <span class="alert-icon alert-icon-amber"><i class="bi bi-box"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <p class="mb-0 fw-medium text-sm text-truncate">
                                    {{ $item->brand }} {{ $item->model_name }}
                                </p>
                                <p class="mb-0 text-muted-foreground text-xs">Low stock</p>
                            </div>
                            <span class="stock-pill {{ $item->stock_qty === 0 ? 'stock-pill-out' : 'stock-pill-low' }}">{{ $item->stock_qty }} left</span>
                        </a>
                    @empty
                        @if ($attentionCount === 0)
                            <div class="list-group-item text-center py-5">
                                <span class="alert-icon alert-icon-green mx-auto mb-2" style="width:3rem;height:3rem;font-size:1.4rem;"><i class="bi bi-check2-circle"></i></span>
                                <p class="mb-0 fw-medium text-sm">All clear</p>
                                <p class="mb-0 text-muted-foreground text-xs">Nothing needs your attention right now.</p>
                            </div>
                        @endif
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

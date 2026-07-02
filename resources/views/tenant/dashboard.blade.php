@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div class="p-4 p-md-5">
    {{-- Header --}}
    <div class="mb-4">
        <p class="section-label mb-1">Dashboard</p>
        <h1 class="h3 fw-semibold font-display mb-1">
            {{ ($first = \Illuminate\Support\Str::of(auth()->user()->name)->before(' ')) ? "Welcome back, $first" : 'Welcome back' }}
        </h1>
        <p class="text-muted-foreground mb-0" style="font-size:.9rem;">Here's how your store is doing today.</p>
    </div>

    {{-- Metrics --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <x-metric-card label="Today's sales" value="₹ {{ number_format($todaySales, 0) }}"
                           hint="Delivered orders" icon="bi-currency-rupee" tone="primary" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Pending orders" value="{{ $pendingCount }}"
                           hint="In the lab" icon="bi-clock-history" :href="safe_route('tenant.orders.index')" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Ready for pickup" value="{{ $readyCount }}"
                           hint="Awaiting customer" icon="bi-bag-check"
                           :href="safe_route('tenant.orders.index')" :tone="$readyCount > 0 ? 'primary' : 'default'" />
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
            <div class="row g-3">
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
                                      style="width:2.5rem;height:2.5rem;"><i class="bi {{ $a['icon'] }} fs-5"></i></span>
                                <div>
                                    <p class="mb-0 fw-medium" style="font-size:.9rem;">{{ $a['label'] }}</p>
                                    <p class="mb-0 text-muted-foreground" style="font-size:.75rem;">{{ $a['desc'] }}</p>
                                </div>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Alerts --}}
        <div class="col-lg-4">
            <h2 class="section-label mb-3">Alerts</h2>
            <div class="card border-0 shadow-sm rounded-4">
                <div class="list-group list-group-flush rounded-4">
                    @if ($subscriptionPastDue)
                        <div class="list-group-item d-flex gap-2 align-items-start">
                            <i class="bi bi-credit-card text-danger mt-1"></i>
                            <div>
                                <p class="mb-0 fw-medium small">Subscription past due</p>
                                <a href="{{ safe_route('tenant.billing.index') }}" class="small text-decoration-none">Renew now</a>
                            </div>
                        </div>
                    @endif

                    @forelse ($dueToPrepare as $o)
                        <a href="{{ safe_route('tenant.orders.show', $o['id']) }}"
                           class="list-group-item list-group-item-action d-flex gap-2 align-items-start">
                            <i class="bi bi-tools {{ $o['overdue_days'] > 0 ? 'text-danger' : 'text-primary' }} mt-1"></i>
                            <div class="flex-grow-1 min-w-0">
                                <p class="mb-0 fw-medium small text-truncate">{{ $o['customer_name'] ?? 'Walk-in' }}</p>
                                <p class="mb-0 text-muted-foreground" style="font-size:.72rem;">
                                    @if ($o['overdue_days'] > 0)
                                        {{ $o['overdue_days'] }} day{{ $o['overdue_days'] == 1 ? '' : 's' }} overdue to prepare
                                    @else
                                        Due to prepare today
                                    @endif
                                </p>
                            </div>
                            <span class="badge text-bg-light">₹{{ number_format($o['total_amount'], 0) }}</span>
                        </a>
                    @empty
                    @endforelse

                    @forelse ($overduePickups as $o)
                        <a href="{{ safe_route('tenant.orders.show', $o['id']) }}"
                           class="list-group-item list-group-item-action d-flex gap-2 align-items-start">
                            <i class="bi bi-hourglass-split text-warning mt-1"></i>
                            <div class="flex-grow-1 min-w-0">
                                <p class="mb-0 fw-medium small text-truncate">{{ $o['customer_name'] ?? 'Walk-in' }}</p>
                                <p class="mb-0 text-muted-foreground" style="font-size:.72rem;">
                                    Ready {{ $o['days'] }} day{{ $o['days'] == 1 ? '' : 's' }} — uncollected
                                </p>
                            </div>
                            <span class="badge text-bg-light">₹{{ number_format($o['total_amount'], 0) }}</span>
                        </a>
                    @empty
                    @endforelse

                    @forelse ($lowStock as $item)
                        <a href="{{ safe_route('tenant.inventory.edit', $item->id) }}"
                           class="list-group-item list-group-item-action d-flex gap-2 align-items-start">
                            <i class="bi bi-box text-warning mt-1"></i>
                            <div class="flex-grow-1 min-w-0">
                                <p class="mb-0 fw-medium small text-truncate">
                                    {{ $item->brand }} {{ $item->model_name }}
                                </p>
                                <p class="mb-0 text-muted-foreground" style="font-size:.72rem;">Low stock</p>
                            </div>
                            <span class="badge text-bg-warning">{{ $item->stock_qty }} left</span>
                        </a>
                    @empty
                        @if (! $subscriptionPastDue && $overduePickups->isEmpty() && $dueToPrepare->isEmpty())
                            <div class="list-group-item text-center text-muted-foreground py-4">
                                <i class="bi bi-check2-circle d-block fs-3 mb-2 text-success"></i>
                                <span class="small">All clear — nothing needs attention.</span>
                            </div>
                        @endif
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

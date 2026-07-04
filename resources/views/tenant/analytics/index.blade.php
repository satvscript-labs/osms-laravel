@extends('layouts.app')
@section('title', 'Analytics')

@php $money = fn ($n) => '₹ ' . number_format($n, 0); @endphp

@section('content')
<div class="p-4 p-md-5">
    {{-- Header + date range --}}
    <div class="d-flex flex-column flex-md-row gap-3 align-items-md-end justify-content-between mb-4">
        <div>
            <p class="section-label mb-1">Store admin</p>
            <h1 class="h3 fw-semibold font-display mb-1">Analytics</h1>
            <p class="text-muted-foreground mb-0 text-md">
                Revenue, COGS, gross profit, and outstanding balances.
            </p>
        </div>
        <form action="{{ route('tenant.analytics.index') }}" method="GET" class="d-flex flex-wrap align-items-end gap-2">
            <div>
                <label for="from" class="form-label section-label mb-1">From</label>
                <input id="from" name="from" type="date" value="{{ $fromStr }}" class="form-control form-control-sm">
            </div>
            <div>
                <label for="to" class="form-label section-label mb-1">To</label>
                <input id="to" name="to" type="date" value="{{ $toStr }}" class="form-control form-control-sm">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        </form>
    </div>

    {{-- Tabs (iOS segmented control) --}}
    <div class="nav segmented mb-4" role="tablist">
        <button class="segmented-item active" data-bs-toggle="pill" data-bs-target="#tab-overview"
                type="button" role="tab" aria-selected="true">
            <i class="bi bi-bar-chart-line"></i> Overview
        </button>
        <button class="segmented-item" data-bs-toggle="pill" data-bs-target="#tab-ledger"
                type="button" role="tab" aria-selected="false">
            <i class="bi bi-journal-text"></i> Ledger
        </button>
        <button class="segmented-item" data-bs-toggle="pill" data-bs-target="#tab-dues"
                type="button" role="tab" aria-selected="false">
            <i class="bi bi-cash-stack"></i> Pending dues
        </button>
    </div>

    <div class="tab-content">
        {{-- Overview --}}
        <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">
            <div class="row g-3 mb-4 stagger">
                <div class="col-6 col-lg-3">
                    <x-metric-card label="Revenue" value="{{ $money($stats['revenue']) }}"
                        hint="{{ $stats['ordersCount'] }} delivered {{ Str::plural('order', $stats['ordersCount']) }}"
                        icon="bi-graph-up-arrow" tone="primary" />
                </div>
                <div class="col-6 col-lg-3">
                    <x-metric-card label="COGS" value="{{ $money($stats['cogs']) }}" hint="Cost of goods sold" icon="bi-box-seam" />
                </div>
                <div class="col-6 col-lg-3">
                    <x-metric-card label="Gross profit" value="{{ $money($stats['profit']) }}"
                        hint="{{ $stats['margin'] !== null ? number_format($stats['margin'], 1) . '% margin' : 'margin — (no cost data)' }}"
                        icon="bi-receipt" tone="primary" />
                </div>
                <div class="col-6 col-lg-3">
                    <x-metric-card label="Avg order value"
                        value="{{ $money($stats['ordersCount'] > 0 ? $stats['revenue'] / $stats['ordersCount'] : 0) }}"
                        icon="bi-stars" />
                </div>
                <div class="col-6 col-lg-3">
                    <x-metric-card label="Discounts given" value="{{ $money($stats['discounts']) }}"
                        hint="Across delivered orders" icon="bi-tag" tone="amber" />
                </div>
            </div>

            @if (($stats['uncostedLines'] ?? 0) > 0)
                <p class="text-muted-foreground mb-4" style="font-size:.75rem;">
                    <i class="bi bi-info-circle me-1"></i>{{ $stats['uncostedLines'] }} sold
                    {{ Str::plural('line', $stats['uncostedLines']) }} {{ $stats['uncostedLines'] === 1 ? 'has' : 'have' }}
                    no recorded cost price — excluded from COGS, profit &amp; margin (revenue still counts).
                </p>
            @endif

            {{-- 5.4 — Collected by payment method (reconcile the cash counter) --}}
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                        <div>
                            <h3 class="section-label mb-1">Collected by payment method</h3>
                            <p class="text-muted-foreground mb-0" style="font-size:.78rem;">
                                Money actually received in this range — reconcile it with your cash drawer and each channel.
                            </p>
                        </div>
                        <div class="text-end">
                            <p class="text-uppercase text-muted-foreground mb-0" style="font-size:.6rem;letter-spacing:.05em;">Total collected</p>
                            <p class="h4 fw-semibold font-display font-monospace mb-0">{{ $money($collectedTotal) }}</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        @php
                            $methods = [
                                'cash'  => ['label' => 'Cash',  'icon' => 'bi-cash-stack',   'tone' => 'green'],
                                'card'  => ['label' => 'Card',  'icon' => 'bi-credit-card-2-front', 'tone' => 'blue'],
                                'upi'   => ['label' => 'UPI',   'icon' => 'bi-phone',        'tone' => 'primary'],
                                'other' => ['label' => 'Other', 'icon' => 'bi-three-dots',   'tone' => 'neutral'],
                            ];
                        @endphp
                        @foreach ($methods as $key => $m)
                            @php
                                $amt = $collectedByMethod[$key] ?? 0;
                                $pct = $collectedTotal > 0 ? $amt / $collectedTotal * 100 : 0;
                            @endphp
                            <div class="col-6 col-lg-3">
                                <div class="rounded-3 p-3 h-100" style="background: var(--surface-sunken);">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary"
                                              style="width:2rem;height:2rem;"><i class="bi {{ $m['icon'] }}"></i></span>
                                        <span class="fw-medium">{{ $m['label'] }}</span>
                                    </div>
                                    <p class="h5 fw-semibold font-display font-monospace mb-1">{{ $money($amt) }}</p>
                                    <div class="progress rounded-pill" style="height:.3rem;background:var(--osms-border);">
                                        <div class="progress-bar bg-primary" role="progressbar" style="width: {{ $pct }}%"
                                             aria-valuenow="{{ (int) $pct }}" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <p class="text-muted-foreground mb-0 mt-1" style="font-size:.68rem;">{{ number_format($pct, 0) }}% of collected</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h3 class="section-label mb-3">Best-selling brands</h3>
                    @if ($topBrands->isEmpty())
                        <p class="text-center text-muted-foreground border border-2 border-dashed rounded-3 py-4 mb-0">No sales data yet.</p>
                    @else
                        @php $max = $topBrands->first()['revenue'] ?: 1; @endphp
                        <div class="d-flex flex-column gap-3 stagger">
                            @foreach ($topBrands as $i => $b)
                                <div class="d-flex align-items-center gap-3">
                                    <span class="font-monospace text-muted-foreground text-xs" style="width:1.5rem;">{{ str_pad($i+1, 2, '0', STR_PAD_LEFT) }}</span>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-baseline mb-1">
                                            <span class="fw-medium text-md">{{ $b['brand'] }}</span>
                                            <span class="text-muted-foreground text-xs">
                                                <span class="font-monospace text-dark">{{ $b['quantity'] }}</span> sold ·
                                                <span class="font-monospace text-dark">{{ $money($b['revenue']) }}</span>
                                            </span>
                                        </div>
                                        <div class="progress" style="height:.4rem;">
                                            <div class="progress-bar bg-primary bar-animate" style="width: {{ max(4, ($b['revenue'] / $max) * 100) }}%"></div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Ledger --}}
        <div class="tab-pane fade" id="tab-ledger" role="tabpanel">
            <div class="d-flex justify-content-end gap-2 mb-2">
                @if ($ledger->count() >= 50 && ! $showAllLedger)
                    <a href="{{ route('tenant.analytics.index', ['from' => $fromStr, 'to' => $toStr, 'ledger_all' => 1]) }}"
                       class="btn btn-light btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Show all</a>
                @elseif ($showAllLedger)
                    <a href="{{ route('tenant.analytics.index', ['from' => $fromStr, 'to' => $toStr]) }}"
                       class="btn btn-light btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Show less</a>
                @endif
            </div>
            <div class="card border-0 shadow-sm rounded-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 osms-orders-table">
                        <thead class="text-muted-foreground text-xs">
                            <tr><th class="ps-4">Date</th><th>Customer</th><th>Status</th>
                                <th class="text-end">Total</th><th class="text-end">Advance</th><th class="text-end pe-4">Balance</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($ledger as $o)
                                <tr role="button" onclick="window.location='{{ route('tenant.orders.show', $o) }}'">
                                    <td class="ps-4 text-sm">{{ $o->created_at->format('d M Y') }}</td>
                                    <td>{{ $o->customer?->name ?? '—' }}</td>
                                    <td><span class="badge text-bg-light text-capitalize">{{ str_replace('_', ' ', $o->status) }}</span></td>
                                    <td class="text-end font-monospace">₹ {{ number_format($o->total_amount, 2) }}</td>
                                    <td class="text-end font-monospace">₹ {{ number_format($o->advance_paid, 2) }}</td>
                                    <td class="text-end pe-4 font-monospace {{ $o->balance_due > 0 ? 'text-danger' : '' }}">₹ {{ number_format($o->balance_due, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted-foreground py-4">No orders in this range.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Pending dues --}}
        <div class="tab-pane fade" id="tab-dues" role="tabpanel">
            @if ($dues->count() >= 50 || $showAllDues)
                <div class="d-flex justify-content-end mb-2">
                    @if ($dues->count() >= 50 && ! $showAllDues)
                        <a href="{{ route('tenant.analytics.index', ['from' => $fromStr, 'to' => $toStr, 'dues_all' => 1]) }}"
                           class="btn btn-light btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Show all</a>
                    @elseif ($showAllDues)
                        <a href="{{ route('tenant.analytics.index', ['from' => $fromStr, 'to' => $toStr]) }}"
                           class="btn btn-light btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Show less</a>
                    @endif
                </div>
            @endif
            <div class="card border-0 shadow-sm rounded-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 osms-orders-table">
                        <thead class="text-muted-foreground text-xs">
                            <tr><th class="ps-4">Customer</th><th>Phone</th><th>Date</th>
                                <th class="text-end">Total</th><th class="text-end">Advance</th><th class="text-end pe-4">Balance due</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($dues as $o)
                                <tr role="button" onclick="window.location='{{ route('tenant.orders.show', $o) }}'">
                                    <td class="ps-4 fw-medium">{{ $o->customer?->name ?? '—' }}</td>
                                    <td>{{ $o->customer?->phone ?? '—' }}</td>
                                    <td class="text-sm">{{ $o->created_at->format('d M Y') }}</td>
                                    <td class="text-end font-monospace">₹ {{ number_format($o->total_amount, 2) }}</td>
                                    <td class="text-end font-monospace">₹ {{ number_format($o->advance_paid, 2) }}</td>
                                    <td class="text-end pe-4 font-monospace text-danger fw-medium">₹ {{ number_format($o->balance_due, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted-foreground py-4">No outstanding balances. 🎉</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

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
            <p class="text-muted-foreground mb-0" style="font-size:.9rem;">
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

    {{-- Tabs --}}
    <ul class="nav nav-pills mb-4 gap-1" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-overview" type="button">Overview</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-ledger" type="button">Ledger</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-dues" type="button">Pending dues</button></li>
    </ul>

    <div class="tab-content">
        {{-- Overview --}}
        <div class="tab-pane fade show active" id="tab-overview">
            <div class="row g-3 mb-4">
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
                        hint="{{ number_format($stats['margin'], 1) }}% margin" icon="bi-receipt" tone="primary" />
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

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h3 class="section-label mb-3">Best-selling brands</h3>
                    @if ($topBrands->isEmpty())
                        <p class="text-center text-muted-foreground border border-2 border-dashed rounded-3 py-4 mb-0">No sales data yet.</p>
                    @else
                        @php $max = $topBrands->first()['revenue'] ?: 1; @endphp
                        <div class="d-flex flex-column gap-3">
                            @foreach ($topBrands as $i => $b)
                                <div class="d-flex align-items-center gap-3">
                                    <span class="font-monospace text-muted-foreground" style="font-size:.78rem;width:1.5rem;">{{ str_pad($i+1, 2, '0', STR_PAD_LEFT) }}</span>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-baseline mb-1">
                                            <span class="fw-medium" style="font-size:.9rem;">{{ $b['brand'] }}</span>
                                            <span class="text-muted-foreground" style="font-size:.78rem;">
                                                <span class="font-monospace text-dark">{{ $b['quantity'] }}</span> sold ·
                                                <span class="font-monospace text-dark">{{ $money($b['revenue']) }}</span>
                                            </span>
                                        </div>
                                        <div class="progress" style="height:.4rem;">
                                            <div class="progress-bar bg-primary" style="width: {{ max(4, ($b['revenue'] / $max) * 100) }}%"></div>
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
        <div class="tab-pane fade" id="tab-ledger">
            <div class="d-flex justify-content-end gap-2 mb-2">
                <a href="{{ route('tenant.analytics.ledger.export', ['from' => $fromStr, 'to' => $toStr]) }}"
                   class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-excel me-1"></i> Export Excel</a>
                @if ($ledger->count() >= 50 && ! $showAllLedger)
                    <a href="{{ route('tenant.analytics.index', ['from' => $fromStr, 'to' => $toStr, 'ledger_all' => 1]) }}"
                       class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Show all</a>
                @elseif ($showAllLedger)
                    <a href="{{ route('tenant.analytics.index', ['from' => $fromStr, 'to' => $toStr]) }}"
                       class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Show less</a>
                @endif
            </div>
            <div class="card border-0 shadow-sm rounded-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="text-muted-foreground" style="font-size:.78rem;">
                            <tr><th class="ps-4">Date</th><th>Customer</th><th>Status</th>
                                <th class="text-end">Total</th><th class="text-end">Advance</th><th class="text-end pe-4">Balance</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($ledger as $o)
                                <tr role="button" onclick="window.location='{{ route('tenant.orders.show', $o) }}'">
                                    <td class="ps-4" style="font-size:.85rem;">{{ $o->created_at->format('d M Y') }}</td>
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
        <div class="tab-pane fade" id="tab-dues">
            @if ($dues->count() >= 50 || $showAllDues)
                <div class="d-flex justify-content-end mb-2">
                    @if ($dues->count() >= 50 && ! $showAllDues)
                        <a href="{{ route('tenant.analytics.index', ['from' => $fromStr, 'to' => $toStr, 'dues_all' => 1]) }}"
                           class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Show all</a>
                    @elseif ($showAllDues)
                        <a href="{{ route('tenant.analytics.index', ['from' => $fromStr, 'to' => $toStr]) }}"
                           class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Show less</a>
                    @endif
                </div>
            @endif
            <div class="card border-0 shadow-sm rounded-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="text-muted-foreground" style="font-size:.78rem;">
                            <tr><th class="ps-4">Customer</th><th>Phone</th><th>Date</th>
                                <th class="text-end">Total</th><th class="text-end">Advance</th><th class="text-end pe-4">Balance due</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($dues as $o)
                                <tr role="button" onclick="window.location='{{ route('tenant.orders.show', $o) }}'">
                                    <td class="ps-4 fw-medium">{{ $o->customer?->name ?? '—' }}</td>
                                    <td>{{ $o->customer?->phone ?? '—' }}</td>
                                    <td style="font-size:.85rem;">{{ $o->created_at->format('d M Y') }}</td>
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

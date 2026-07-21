@extends('layouts.app')
@section('title', 'Platform overview')

@section('content')
<div class="p-4 p-md-5">
    <div class="mb-4">
        <p class="section-label mb-1">Platform</p>
        <h1 class="h3 fw-semibold font-display mb-1">Overview</h1>
        <p class="text-muted-foreground mb-0" style="font-size:var(--text-md);">How the OSMS business is doing across all stores.</p>
    </div>

    {{-- KPIs --}}
    <div class="row g-3 mb-4 stagger">
        <div class="col-6 col-lg-3">
            <x-metric-card label="MRR" value="₹ {{ number_format($stats['mrr'], 0) }}" icon="bi-graph-up-arrow" tone="primary" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Active subscriptions" value="{{ $stats['active'] }}" icon="bi-patch-check" tone="default" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="On trial" value="{{ $stats['trialing'] }}" icon="bi-hourglass-split" tone="amber" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Churned (30d)" value="{{ $stats['churn30'] }}" icon="bi-arrow-down-right" tone="{{ $stats['churn30'] > 0 ? 'amber' : 'default' }}" />
        </div>
    </div>

    <div class="row g-3 mb-4 stagger">
        <div class="col-6 col-lg-3">
            <x-metric-card label="Total stores" value="{{ $stats['stores'] }}" icon="bi-shop" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Store users" value="{{ $stats['users'] }}" icon="bi-people" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Orders (all)" value="{{ $stats['orders'] }}" icon="bi-cart3" />
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('superadmin.tenants.index') }}" class="text-decoration-none">
                <x-metric-card label="Manage stores" value="→" icon="bi-arrow-right-circle" tone="primary" />
            </a>
        </div>
    </div>

    <div class="row g-4">
        {{-- Trials at risk --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <p class="section-label mb-0">Trials ending soon</p>
                        <span class="badge text-bg-warning">{{ $atRisk->count() }}</span>
                    </div>
                    @forelse ($atRisk as $s)
                        <a href="{{ route('superadmin.tenants.show', $s->tenant) }}"
                           class="d-flex align-items-center gap-3 p-2 rounded-3 text-decoration-none search-result-item mb-1">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning-emphasis"
                                  style="width:2.25rem;height:2.25rem;"><i class="bi bi-hourglass-bottom"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <p class="mb-0 fw-medium text-truncate">{{ $s->tenant?->store_name ?? '—' }}</p>
                                <p class="mb-0 text-muted-foreground" style="font-size:var(--text-sm);">
                                    {{ $s->trialDaysLeft() === 0 ? 'Ends today' : $s->trialDaysLeft() . ' day' . ($s->trialDaysLeft() === 1 ? '' : 's') . ' left' }}
                                </p>
                            </div>
                            <i class="bi bi-chevron-right text-faint"></i>
                        </a>
                    @empty
                        <p class="text-muted-foreground text-center py-4 mb-0" style="font-size:var(--text-sm);">No trials ending in the next 3 days.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Recent signups --}}
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Recent signups</p>
                    @forelse ($recent as $t)
                        <a href="{{ route('superadmin.tenants.show', $t) }}"
                           class="d-flex align-items-center gap-3 p-2 rounded-3 text-decoration-none search-result-item mb-1">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary fw-semibold"
                                  style="width:2.25rem;height:2.25rem;">{{ strtoupper(substr($t->store_name, 0, 1)) }}</span>
                            <div class="flex-grow-1 min-w-0">
                                <p class="mb-0 fw-medium text-truncate">{{ $t->store_name }}</p>
                                <p class="mb-0 text-muted-foreground" style="font-size:var(--text-sm);">{{ $t->created_at?->format('d M Y') }}</p>
                            </div>
                            @php $st = $t->subscription?->status; @endphp
                            <span class="badge {{ in_array($st, ['active','trialing']) ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $st ?? 'none' }}</span>
                        </a>
                    @empty
                        <p class="text-muted-foreground text-center py-4 mb-0" style="font-size:var(--text-sm);">No stores yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

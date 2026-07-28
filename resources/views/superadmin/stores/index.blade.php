@extends('layouts.app')
@section('title', 'Stores')

@section('content')
{{--
    P2 — the operational sweep. Deliberately separate from Customers: this
    answers "which shops are live, quiet, or in trouble?", not "who pays me?".
    Sorted by activity, so the busiest stores surface first.
--}}
<div class="p-4 p-md-5">

    <div class="mb-4">
        <p class="section-label mb-1">Platform</p>
        <h1 class="h3 fw-semibold font-display mb-1">Stores</h1>
        <p class="text-muted-foreground mb-0 text-md">
            Every shop across every customer — which are live, quiet, or suspended.
        </p>
    </div>

    <form method="GET" class="d-flex flex-column flex-lg-row gap-3 align-items-lg-center justify-content-between mb-3">
        <div class="input-group flex-nowrap" style="max-width:30rem;">
            <span class="input-group-text bg-white border-end-0">
                <i class="bi bi-search text-muted-foreground"></i>
            </span>
            <input type="search" name="q" value="{{ $search }}" class="form-control border-start-0"
                   placeholder="Search store or customer…" autocomplete="off" aria-label="Search stores">
            @if ($filter !== 'all')<input type="hidden" name="filter" value="{{ $filter }}">@endif
            <button class="btn btn-light border-start-0" type="submit" aria-label="Search"><i class="bi bi-arrow-right"></i></button>
        </div>

        <div class="segmented" role="group" aria-label="Filter stores">
            @foreach ([
                'all' => ['All', $counts['all']],
                'quiet' => ['No orders', $counts['quiet']],
                'unbilled' => ['Not billed', $counts['unbilled']],
                'suspended' => ['Suspended', $counts['suspended']],
            ] as $key => [$label, $count])
                <a href="{{ route('superadmin.stores.index', array_filter(['filter' => $key, 'q' => $search])) }}"
                   class="segmented-item {{ $filter === $key ? 'active' : '' }}">
                    {{ $label }} <span class="text-faint">{{ $count }}</span>
                </a>
            @endforeach
        </div>
    </form>

    <p class="text-muted-foreground text-sm mb-3">
        {{ $stores->total() }} {{ Str::plural('store', $stores->total()) }}
    </p>

    @if ($stores->isNotEmpty())
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden stagger">
            @foreach ($stores as $store)
                <a href="{{ route('superadmin.stores.show', $store) }}" class="person-row">
                    <span class="person-avatar"><i class="bi bi-shop"></i></span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="fw-semibold text-truncate">{{ $store->store_name }}</span>
                            @if ($store->store_status !== 'active')
                                <span class="osms-badge osms-badge-red"><span class="osms-badge-dot"></span>Suspended</span>
                            @endif
                            @unless ($store->is_billable)
                                <span class="meta-chip">not billed</span>
                            @endunless
                        </div>
                        <div class="text-muted-foreground text-sm mt-1 d-flex align-items-center gap-2 flex-wrap">
                            @if ($store->account)
                                <span><i class="bi bi-person-vcard me-1"></i>{{ $store->account->displayName() }}</span>
                            @else
                                <span class="text-faint"><i class="bi bi-exclamation-triangle me-1"></i>No customer linked</span>
                            @endif
                        </div>
                    </div>
                    <div class="d-none d-sm-flex align-items-center gap-2">
                        <span class="meta-chip"><i class="bi bi-people"></i> {{ number_format($store->customers_count) }}</span>
                        <span class="meta-chip"><i class="bi bi-cart3"></i> {{ number_format($store->orders_count) }}</span>
                    </div>
                    <i class="bi bi-chevron-right person-chevron"></i>
                </a>
            @endforeach
        </div>

        @if ($stores->hasPages())
            <div class="mt-3">{{ $stores->links() }}</div>
        @endif
    @else
        <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
            <span class="d-inline-flex align-items-center justify-content-center rounded-circle person-avatar mx-auto mb-3"
                  style="width:3.25rem;height:3.25rem;font-size:1.2rem;"><i class="bi bi-shop"></i></span>
            <h2 class="h5 fw-semibold font-display">No stores match</h2>
            <p class="text-muted-foreground mb-0">Try a different search or filter.</p>
        </div>
    @endif
</div>
@endsection

@extends('layouts.app')
@section('title', 'Stores')

@section('content')
{{--
    P2 — the operational sweep. Deliberately separate from Customers: this
    answers "which shops are live, quiet, or in trouble?", not "who pays me?".
    Sorted by activity, so the busiest stores surface first.

    P6 — filters live now. It was the last operator list still submitting a
    form and reloading the whole page, which the LIQUID MOTION STANDARD does
    not allow. Transport comes from the shared `superadminList()` factory; only
    the row markup is local, because only the row markup genuinely differs.
--}}
<div class="p-4 p-md-5"
     x-data="superadminList({
        endpoint: @js(route('superadmin.stores.index')),
        query: @js($search),
        filter: @js($filter),
        serverTotal: {{ $stores->total() }},
        noun: 'store',
     })">

    <div class="mb-4">
        <p class="section-label mb-1">Platform</p>
        <h1 class="h3 fw-semibold font-display mb-1">Stores</h1>
        <p class="text-muted-foreground mb-0 text-md">
            Every shop across every customer — which are live, quiet, or suspended.
        </p>
    </div>

    <div class="d-flex flex-column flex-lg-row gap-3 align-items-lg-center justify-content-between mb-3">
        <div class="input-group flex-nowrap" style="max-width:30rem;">
            <span class="input-group-text bg-white border-end-0">
                <i class="bi bi-search text-muted-foreground"></i>
            </span>
            <input type="search" x-model="query" @input.debounce.220ms="refresh()"
                   class="form-control border-start-0"
                   placeholder="Search store or customer…" autocomplete="off"
                   aria-label="Search stores">
            <span class="input-group-text bg-white border-start-0" x-show="loading" x-cloak>
                <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
            </span>
            <button type="button" class="btn btn-light border-start-0" x-show="query && !loading" x-cloak
                    @click="query=''; refresh()" aria-label="Clear search">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="segmented" role="group" aria-label="Filter stores">
            @foreach ([
                'all' => ['All', $counts['all']],
                'quiet' => ['No orders', $counts['quiet']],
                'unbilled' => ['Not billed', $counts['unbilled']],
                'suspended' => ['Suspended', $counts['suspended']],
            ] as $key => [$label, $count])
                <button type="button" class="segmented-item" :class="{ 'active': filter==='{{ $key }}' }"
                        @click="setFilter('{{ $key }}')">
                    {{ $label }} <span class="text-faint">{{ $count }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <p class="text-muted-foreground text-sm mb-3" x-cloak>
        <span x-text="displayTotal()"></span>
    </p>

    {{-- ============ LIVE results ============ --}}
    <template x-if="mode==='live'">
        <div aria-live="polite" :aria-busy="loading ? 'true' : 'false'">
            {{-- Never a bare spinner over a blank list. --}}
            <template x-if="loading">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <template x-for="i in 5" :key="i">
                        <div class="person-row">
                            <span class="skeleton rounded-circle" style="width:2.7rem;height:2.7rem;"></span>
                            <div class="flex-grow-1">
                                <div class="skeleton mb-2" style="height:.8rem;width:35%;"></div>
                                <div class="skeleton" style="height:.7rem;width:20%;"></div>
                            </div>
                            <div class="skeleton d-none d-md-block" style="height:.8rem;width:5rem;"></div>
                        </div>
                    </template>
                </div>
            </template>

            <template x-if="!loading && rows.length">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden stagger" :key="listKey">
                    <template x-for="s in rows" :key="s.id">
                        <a :href="s.url" class="person-row">
                            <span class="person-avatar"><i class="bi bi-shop"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="fw-semibold text-truncate" x-text="s.name"></span>
                                    <template x-if="s.closed">
                                        <span class="osms-badge osms-badge-red"><span class="osms-badge-dot"></span>Closed</span>
                                    </template>
                                    <template x-if="!s.closed && s.status !== 'active'">
                                        <span class="osms-badge osms-badge-amber"><span class="osms-badge-dot"></span>Suspended</span>
                                    </template>
                                    <template x-if="!s.billable">
                                        <span class="meta-chip">not billed</span>
                                    </template>
                                </div>
                                <div class="text-muted-foreground text-sm mt-1">
                                    <template x-if="s.account">
                                        <span><i class="bi bi-person-vcard me-1"></i><span x-text="s.account"></span></span>
                                    </template>
                                    <template x-if="!s.account">
                                        <span class="text-faint"><i class="bi bi-exclamation-triangle me-1"></i>No customer linked</span>
                                    </template>
                                </div>
                            </div>
                            <div class="d-none d-sm-flex align-items-center gap-2">
                                <span class="meta-chip"><i class="bi bi-people"></i> <span x-text="money(s.customers)"></span></span>
                                <span class="meta-chip"><i class="bi bi-cart3"></i> <span x-text="money(s.orders)"></span></span>
                            </div>
                            <i class="bi bi-chevron-right person-chevron"></i>
                        </a>
                    </template>
                </div>
            </template>

            <template x-if="!loading && !rows.length">
                <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle person-avatar person-avatar-lg mx-auto mb-3"><i class="bi bi-search"></i></span>
                    <h2 class="h5 fw-semibold font-display">No stores match</h2>
                    <p class="text-muted-foreground mb-0">Try a different search or filter.</p>
                </div>
            </template>
        </div>
    </template>

    {{-- ============ IDLE (server-rendered) ============ --}}
    <div x-show="mode==='idle'">
        @if ($rows->isNotEmpty())
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden stagger">
                @foreach ($rows as $s)
                    <a href="{{ $s['url'] }}" class="person-row">
                        <span class="person-avatar"><i class="bi bi-shop"></i></span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold text-truncate">{{ $s['name'] }}</span>
                                @if ($s['closed'])
                                    <span class="osms-badge osms-badge-red"><span class="osms-badge-dot"></span>Closed</span>
                                @elseif ($s['status'] !== 'active')
                                    <span class="osms-badge osms-badge-amber"><span class="osms-badge-dot"></span>Suspended</span>
                                @endif
                                @unless ($s['billable'])
                                    <span class="meta-chip">not billed</span>
                                @endunless
                            </div>
                            <div class="text-muted-foreground text-sm mt-1">
                                @if ($s['account'])
                                    <span><i class="bi bi-person-vcard me-1"></i>{{ $s['account'] }}</span>
                                @else
                                    <span class="text-faint"><i class="bi bi-exclamation-triangle me-1"></i>No customer linked</span>
                                @endif
                            </div>
                        </div>
                        <div class="d-none d-sm-flex align-items-center gap-2">
                            <span class="meta-chip"><i class="bi bi-people"></i> {{ number_format($s['customers']) }}</span>
                            <span class="meta-chip"><i class="bi bi-cart3"></i> {{ number_format($s['orders']) }}</span>
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
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle person-avatar person-avatar-lg mx-auto mb-3"><i class="bi bi-shop"></i></span>
                <h2 class="h5 fw-semibold font-display">No stores match</h2>
                <p class="text-muted-foreground mb-0">Try a different search or filter.</p>
            </div>
        @endif
    </div>
</div>

@include('superadmin.partials.live-list')
@endsection

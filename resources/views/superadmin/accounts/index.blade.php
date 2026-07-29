@extends('layouts.app')
@section('title', 'Customers')

@section('content')
{{--
    P2 — the panel's daily surface. One row per ACCOUNT (the payer), not per
    store. Live-filtered via Alpine + a JSON endpoint, debounced 220ms, with a
    skeleton state and staggered entrance — the same structure as the tenant
    customers list, per CLAUDE.md's reference-implementation rule.
--}}
<div class="p-4 p-md-5"
     x-data="superadminList({
        endpoint: @js(route('superadmin.accounts.index')),
        query: @js($search),
        filter: @js($filter),
        serverTotal: {{ $accounts->total() }},
        noun: 'customer',
     })">

    <div class="d-flex flex-column flex-md-row gap-3 align-items-md-end justify-content-between mb-4">
        <div>
            <p class="section-label mb-1">Platform</p>
            <h1 class="h3 fw-semibold font-display mb-1">Customers</h1>
            <p class="text-muted-foreground mb-0 text-md">
                Who pays, what they pay, and who needs chasing.
            </p>
        </div>

        {{-- P5 / REQ-2 — the door that did not exist until now: selling to
             somebody who has never visited the site. --}}
        <a href="{{ route('superadmin.accounts.create') }}" class="btn btn-primary flex-shrink-0">
            <i class="bi bi-person-plus me-1"></i> New customer
        </a>
    </div>

    {{-- Search + segmented filter. One row on phones: the search flexes, the
         filter wraps beneath rather than stacking three full-width bars. --}}
    <div class="d-flex flex-column flex-lg-row gap-3 align-items-lg-center justify-content-between mb-3">
        <div class="input-group flex-nowrap" style="max-width:30rem;">
            <span class="input-group-text bg-white border-end-0">
                <i class="bi bi-search text-muted-foreground"></i>
            </span>
            <input type="search" x-model="query" @input.debounce.220ms="refresh()"
                   class="form-control border-start-0"
                   placeholder="Search customer, email, or store…" autocomplete="off"
                   aria-label="Search customers">
            <span class="input-group-text bg-white border-start-0" x-show="loading" x-cloak>
                <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
            </span>
            <button type="button" class="btn btn-light border-start-0" x-show="query && !loading" x-cloak
                    @click="query=''; refresh()" aria-label="Clear search">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="segmented" role="group" aria-label="Filter customers">
            <button type="button" class="segmented-item" :class="{ 'active': filter==='attention' }" @click="setFilter('attention')">
                <i class="bi bi-exclamation-circle"></i> Needs me
            </button>
            <button type="button" class="segmented-item" :class="{ 'active': filter==='all' }" @click="setFilter('all')">
                All <span class="text-faint">{{ $counts['all'] }}</span>
            </button>
            <button type="button" class="segmented-item" :class="{ 'active': filter==='trialing' }" @click="setFilter('trialing')">
                Trial <span class="text-faint">{{ $counts['trialing'] }}</span>
            </button>
            <button type="button" class="segmented-item" :class="{ 'active': filter==='paid' }" @click="setFilter('paid')">
                Paying <span class="text-faint">{{ $counts['paid'] }}</span>
            </button>
            <button type="button" class="segmented-item" :class="{ 'active': filter==='lapsed' }" @click="setFilter('lapsed')">
                Lapsed <span class="text-faint">{{ $counts['lapsed'] }}</span>
            </button>
        </div>
    </div>

    <p class="text-muted-foreground text-sm mb-3" x-cloak>
        <span x-text="displayTotal()"></span>
    </p>

    {{-- ============ LIVE results ============ --}}
    <template x-if="mode==='live'">
        <div aria-live="polite" :aria-busy="loading ? 'true' : 'false'">
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
                    <template x-for="a in rows" :key="a.id">
                        <a :href="a.url" class="person-row">
                            <span class="person-avatar" x-text="initial(a.name)"></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="fw-semibold text-truncate" x-text="a.name"></span>
                                    <span class="osms-badge" :class="pillTone(a)">
                                        <span class="osms-badge-dot"></span><span x-text="pillLabel(a)"></span>
                                    </span>
                                    <template x-if="a.bespoke">
                                        <span class="meta-chip" title="On a hand-agreed price">⚑ bespoke</span>
                                    </template>
                                    <template x-if="a.override">
                                        <span class="meta-chip text-capitalize" x-text="a.override"></span>
                                    </template>
                                </div>
                                <div class="text-muted-foreground text-sm mt-1 d-flex align-items-center gap-2 flex-wrap">
                                    <span x-show="a.email" x-text="a.email"></span>
                                    <span class="meta-chip">
                                        <i class="bi bi-shop"></i> <span x-text="a.stores"></span>
                                    </span>
                                </div>
                            </div>
                            <div class="d-none d-md-block text-end" style="min-width:7rem;">
                                <div class="text-3xs text-faint text-uppercase" style="letter-spacing:.05em;">Renews</div>
                                <div class="text-sm" x-text="a.renews || '—'"></div>
                            </div>
                            <div class="d-none d-lg-block text-end" style="min-width:6rem;">
                                <div class="text-3xs text-faint text-uppercase" style="letter-spacing:.05em;">Lifetime</div>
                                <div class="text-sm">₹ <span x-text="money(a.lifetime)"></span></div>
                            </div>
                            <i class="bi bi-chevron-right person-chevron"></i>
                        </a>
                    </template>
                </div>
            </template>

            <template x-if="!loading && !rows.length">
                <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle person-avatar mx-auto mb-3 person-avatar-lg"><i class="bi bi-search"></i></span>
                    <h2 class="h5 fw-semibold font-display">No customers match</h2>
                    <p class="text-muted-foreground mb-0">Try a different name, email, or store.</p>
                </div>
            </template>
        </div>
    </template>

    {{-- ============ IDLE (server-rendered) ============ --}}
    <div x-show="mode==='idle'">
        @if ($rows->isNotEmpty())
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden stagger">
                @foreach ($rows as $a)
                    <a href="{{ $a['url'] }}" class="person-row">
                        <span class="person-avatar">{{ mb_strtoupper(mb_substr($a['name'], 0, 1)) }}</span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold text-truncate">{{ $a['name'] }}</span>
                                @include('superadmin.partials.status-pill', ['status' => $a['status'], 'access' => $a['access']])
                                @if ($a['bespoke'])
                                    <span class="meta-chip" title="On a hand-agreed price">⚑ bespoke</span>
                                @endif
                                @if ($a['override'])
                                    <span class="meta-chip text-capitalize">{{ $a['override'] }}</span>
                                @endif
                            </div>
                            <div class="text-muted-foreground text-sm mt-1 d-flex align-items-center gap-2 flex-wrap">
                                @if ($a['email'])<span>{{ $a['email'] }}</span>@endif
                                <span class="meta-chip"><i class="bi bi-shop"></i> {{ $a['stores'] }}</span>
                            </div>
                        </div>
                        <div class="d-none d-md-block text-end" style="min-width:7rem;">
                            <div class="text-3xs text-faint text-uppercase" style="letter-spacing:.05em;">Renews</div>
                            <div class="text-sm">
                                {{ $a['renews'] ?? '—' }}
                                @if ($a['days_left'] !== null && $a['days_left'] <= 14)
                                    <span class="d-block text-3xs" style="color:var(--tone-amber);">
                                        {{ $a['days_left'] < 0 ? 'expired' : 'in ' . $a['days_left'] . 'd' }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        <div class="d-none d-lg-block text-end" style="min-width:6rem;">
                            <div class="text-3xs text-faint text-uppercase" style="letter-spacing:.05em;">Lifetime</div>
                            <div class="text-sm">₹ {{ number_format($a['lifetime'], 0) }}</div>
                        </div>
                        <i class="bi bi-chevron-right person-chevron"></i>
                    </a>
                @endforeach
            </div>

            @if ($accounts->hasPages())
                <div class="mt-3">{{ $accounts->links() }}</div>
            @endif
        @else
            <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle person-avatar mx-auto mb-3 person-avatar-lg"><i class="bi bi-person-vcard"></i></span>
                <h2 class="h5 fw-semibold font-display">
                    {{ $filter === 'attention' ? 'Nothing needs you' : 'No customers yet' }}
                </h2>
                <p class="text-muted-foreground mb-0">
                    {{ $filter === 'attention'
                        ? 'No overdue payments, and nothing expiring in the next two weeks.'
                        : 'Customers appear here as stores are provisioned.' }}
                </p>
            </div>
        @endif
    </div>
</div>

@include('superadmin.partials.live-list')
@endsection

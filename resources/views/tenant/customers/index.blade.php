@extends('layouts.app')
@section('title', 'Customers')

@php
    // Server-side initials for the initial (idle) render — mirrored in JS for live rows.
    $initials = fn ($name) => collect(preg_split('/\s+/', trim((string) $name)))
        ->filter()->take(2)
        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
        ->implode('') ?: '?';
@endphp

@section('content')
<div class="p-4 p-md-5"
     x-data="customersIndex({
        endpoint: @js(route('tenant.customers.index')),
        query: @js($search),
        filter: @js(in_array($filter, ['patients', 'birthdays'], true) ? $filter : 'all'),
        serverTotal: {{ $customers->total() }},
     })">

    {{-- Header --}}
    <div class="d-flex flex-column flex-md-row gap-3 align-items-md-end justify-content-between mb-4">
        <div>
            <p class="section-label mb-1">Workspace</p>
            <h1 class="h3 fw-semibold font-display mb-1">Customers</h1>
            <p class="text-muted-foreground mb-0 text-md">
                Manage customer profiles, prescriptions, and order history.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('tenant.customers.trash') }}" class="btn btn-light">
                <i class="bi bi-archive me-1"></i> Archive
            </a>
            <a href="{{ route('tenant.customers.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> New customer
            </a>
        </div>
    </div>

    {{-- Search + segmented filter --}}
    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mb-3">
        <div class="input-group flex-nowrap" style="max-width:30rem;">
            <span class="input-group-text bg-white border-end-0">
                <i class="bi bi-search text-muted-foreground"></i>
            </span>
            <input type="search" x-model="query" @input.debounce.220ms="refresh()"
                   class="form-control border-start-0"
                   placeholder="Search by name or phone…" autocomplete="off" aria-label="Search customers">
            {{-- Live spinner / clear affordance --}}
            <span class="input-group-text bg-white border-start-0" x-show="loading" x-cloak>
                <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
            </span>
            <button type="button" class="btn btn-light border-start-0" x-show="query && !loading" x-cloak
                    @click="query=''; refresh()" aria-label="Clear search">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="segmented" role="group" aria-label="Filter customers">
            <button type="button" class="segmented-item" :class="{ 'active': filter==='all' }" @click="setFilter('all')">
                All
            </button>
            <button type="button" class="segmented-item" :class="{ 'active': filter==='patients' }" @click="setFilter('patients')">
                <i class="bi bi-clipboard2-pulse"></i> Patients
            </button>
            <button type="button" class="segmented-item" :class="{ 'active': filter==='birthdays' }" @click="setFilter('birthdays')">
                <i class="bi bi-balloon"></i> Birthdays
            </button>
        </div>
    </div>

    {{-- Result count --}}
    <p class="text-muted-foreground text-sm mb-3" x-cloak>
        <span x-text="displayTotal()"></span>
        <template x-if="filter==='patients'"><span> with a prescription on file</span></template>
        <template x-if="filter==='birthdays'"><span> with a birthday in the next 7 days 🎂</span></template>
    </p>

    {{-- ============ LIVE (Alpine) results ============ --}}
    <template x-if="mode==='live'">
        <div>
            {{-- Skeleton while loading --}}
            <template x-if="loading">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <template x-for="i in 6" :key="i">
                        <div class="person-row">
                            <span class="skeleton rounded-circle" style="width:2.7rem;height:2.7rem;"></span>
                            <div class="flex-grow-1">
                                <div class="skeleton mb-2" style="height:.8rem;width:40%;"></div>
                                <div class="skeleton" style="height:.7rem;width:25%;"></div>
                            </div>
                            <div class="skeleton d-none d-md-block" style="height:.8rem;width:5rem;"></div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Results --}}
            <template x-if="!loading && rows.length">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden stagger" :key="listKey">
                    <template x-for="c in rows" :key="c.id">
                        <a :href="c.url" class="person-row">
                            <span class="person-avatar" x-text="initials(c.name)"></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="fw-semibold text-truncate" x-text="c.name"></span>
                                    <template x-if="c.is_patient">
                                        <span class="osms-badge osms-badge-blue" title="Has a prescription on file">
                                            <span class="osms-badge-dot"></span> Patient
                                        </span>
                                    </template>
                                    <template x-if="c.days_until_birthday !== null && c.days_until_birthday <= 7">
                                        <span class="birthday-chip" x-text="birthdayLabel(c.days_until_birthday)"></span>
                                    </template>
                                </div>
                                <div class="text-muted-foreground text-sm mt-1">
                                    <i class="bi bi-telephone me-1"></i><span x-text="c.phone"></span>
                                </div>
                            </div>
                            <div class="d-none d-sm-flex align-items-center gap-2">
                                <template x-if="c.age">
                                    <span class="meta-chip"><i class="bi bi-person"></i> <span x-text="c.age"></span> yrs</span>
                                </template>
                                <template x-if="c.gender">
                                    <span class="meta-chip text-capitalize" x-text="c.gender"></span>
                                </template>
                            </div>
                            <div class="d-none d-md-block text-end" style="min-width:5.5rem;">
                                <div class="text-3xs text-faint text-uppercase" style="letter-spacing:.05em;">Added</div>
                                <div class="text-sm" x-text="c.added"></div>
                            </div>
                            <i class="bi bi-chevron-right person-chevron"></i>
                        </a>
                    </template>
                </div>
            </template>

            {{-- "More results" hint --}}
            <template x-if="!loading && rows.length && hasMore">
                <p class="text-center text-muted-foreground text-sm mt-3">
                    Showing the first <span x-text="rows.length"></span>. Refine your search to narrow it down.
                </p>
            </template>

            {{-- Live empty state --}}
            <template x-if="!loading && !rows.length">
                <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle person-avatar mx-auto mb-3"
                          style="width:3.25rem;height:3.25rem;font-size:1.2rem;"><i class="bi bi-search"></i></span>
                    <h2 class="h5 fw-semibold font-display">No customers match your search</h2>
                    <p class="text-muted-foreground mb-0">Try a different name or phone number.</p>
                </div>
            </template>
        </div>
    </template>

    {{-- ============ IDLE (server-rendered) results ============ --}}
    {{-- No x-cloak: idle is always the correct initial state, so let the server
         rows paint immediately (with the page-enter fade) instead of flashing. --}}
    <div x-show="mode==='idle'">
        @if ($customers->isNotEmpty())
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden stagger">
                @foreach ($customers as $c)
                    <a href="{{ route('tenant.customers.show', $c) }}" class="person-row">
                        <span class="person-avatar">{{ $initials($c->name) }}</span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold text-truncate">{{ $c->name }}</span>
                                @if ($c->eye_records_count > 0)
                                    <span class="osms-badge osms-badge-blue" title="Has a prescription on file">
                                        <span class="osms-badge-dot"></span> Patient
                                    </span>
                                @endif
                                @php $bdayDays = $c->isMinor() ? null : $c->daysUntilBirthday(); @endphp
                                @if (! is_null($bdayDays) && $bdayDays <= 7)
                                    <span class="birthday-chip">🎂 {{ $bdayDays === 0 ? 'Today' : ($bdayDays === 1 ? 'Tomorrow' : 'in ' . $bdayDays . ' days') }}</span>
                                @endif
                            </div>
                            <div class="text-muted-foreground text-sm mt-1">
                                <i class="bi bi-telephone me-1"></i>{{ $c->phone }}
                            </div>
                        </div>
                        <div class="d-none d-sm-flex align-items-center gap-2">
                            @if ($c->age)
                                <span class="meta-chip"><i class="bi bi-person"></i> {{ $c->age }} yrs</span>
                            @endif
                            @if ($c->gender)
                                <span class="meta-chip text-capitalize">{{ $c->gender }}</span>
                            @endif
                        </div>
                        <div class="d-none d-md-block text-end" style="min-width:5.5rem;">
                            <div class="text-3xs text-faint text-uppercase" style="letter-spacing:.05em;">Added</div>
                            <div class="text-sm">{{ $c->created_at->format('d M Y') }}</div>
                        </div>
                        <i class="bi bi-chevron-right person-chevron"></i>
                    </a>
                @endforeach
            </div>

            @if ($customers->hasPages())
                <div class="mt-3">{{ $customers->links() }}</div>
            @endif
        @else
            @php
                $isPatientFilter = $filter === 'patients';
                $isBirthdayFilter = $filter === 'birthdays';
                $emptyIcon = $isBirthdayFilter ? 'bi-balloon' : 'bi-people';
            @endphp
            <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle person-avatar mx-auto mb-3"
                      style="width:3.25rem;height:3.25rem;font-size:1.3rem;"><i class="bi {{ $emptyIcon }}"></i></span>
                <h2 class="h5 fw-semibold font-display">
                    @if ($isBirthdayFilter) No birthdays this week
                    @elseif ($isPatientFilter) No patients yet
                    @else No customers yet @endif
                </h2>
                <p class="text-muted-foreground mb-3">
                    @if ($isBirthdayFilter)
                        No customers have a birthday in the next 7 days. Add birthdays on customer profiles to see them here. 🎂
                    @elseif ($isPatientFilter)
                        Customers with a prescription on file appear here. Add an eye record to a customer to make them a patient.
                    @else
                        Add your first customer to start tracking prescriptions and orders.
                    @endif
                </p>
                @unless ($isPatientFilter || $isBirthdayFilter)
                    <a href="{{ route('tenant.customers.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i> Add your first customer
                    </a>
                @endunless
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
    function customersIndex(config) {
        return {
            endpoint: config.endpoint,
            query: config.query || '',
            filter: config.filter || 'all',
            serverTotal: config.serverTotal || 0,
            mode: 'idle',          // 'idle' = server rows · 'live' = fetched rows
            loading: false,
            rows: [],
            total: 0,
            hasMore: false,
            listKey: 0,            // bump to replay the staggered entrance
            _controller: null,

            initials(name) {
                return ((name || '').trim().split(/\s+/).slice(0, 2)
                    .map(w => (w[0] || '').toUpperCase()).join('')) || '?';
            },

            // Festive chip label for an upcoming birthday (days from today).
            birthdayLabel(days) {
                if (days === 0) return '🎂 Today';
                if (days === 1) return '🎂 Tomorrow';
                return '🎂 in ' + days + ' days';
            },

            displayTotal() {
                const n = this.mode === 'live' ? this.total : this.serverTotal;
                return n + ' ' + (n === 1 ? 'customer' : 'customers');
            },

            // Whether we should leave the server-rendered view and fetch live.
            get isDefaultView() {
                return this.query.trim() === '' && this.filter === 'all';
            },

            setFilter(f) {
                if (this.filter === f) return;
                this.filter = f;
                this.refresh();
            },

            _url(forFetch) {
                const u = new URL(this.endpoint, window.location.origin);
                if (this.query.trim()) u.searchParams.set('q', this.query.trim());
                if (this.filter !== 'all') u.searchParams.set('filter', this.filter);
                return u.toString();
            },

            syncUrl() {
                window.history.replaceState({}, '', this._url());
            },

            refresh() {
                this.syncUrl();

                // Returning to the default view — show the server-rendered rows, no fetch.
                if (this.isDefaultView) {
                    this.mode = 'idle';
                    this.rows = [];
                    if (this._controller) this._controller.abort();
                    this.loading = false;
                    return;
                }

                this.mode = 'live';
                this.loading = true;
                if (this._controller) this._controller.abort();
                this._controller = new AbortController();

                fetch(this._url(true), {
                    headers: { 'Accept': 'application/json' },
                    signal: this._controller.signal,
                })
                    .then(r => r.json())
                    .then(d => {
                        this.rows = d.customers || [];
                        this.total = d.total || 0;
                        this.hasMore = !!d.has_more;
                        this.loading = false;
                        this.listKey++;   // replay stagger animation
                    })
                    .catch(err => { if (err.name !== 'AbortError') this.loading = false; });
            },
        };
    }
</script>
@endpush
@endsection

{{-- Table view: live search/filter/sort/paginate via partial-HTML swap (no reload). --}}
<div x-data="ordersLive({
        base: @js(route('tenant.orders.index')),
        q: @js($search),
        status: @js($status),
        payment: @js($payment),
        sort: @js($sort),
        dir: @js($dir),
     })">

    {{-- Filter / search toolbar --}}
    <div class="d-flex flex-wrap gap-2 mb-3">
        <div class="input-group flex-nowrap flex-grow-1" style="min-width:16rem;max-width:26rem;">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted-foreground"></i></span>
            <input id="orderSearch" type="search" x-model="q" @input.debounce.220ms="reload()"
                   class="form-control border-start-0" autocomplete="off"
                   placeholder="Search customer name or phone…  ( / )" aria-label="Search orders">
            <span class="input-group-text bg-white border-start-0" x-show="loading" x-cloak>
                <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
            </span>
            <button type="button" class="btn btn-light border-start-0" x-cloak
                    x-show="(q || status || payment) && !loading"
                    @click="q=''; status=''; payment=''; reload()" aria-label="Clear filters">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <select x-model="status" @change="reload()" class="form-select w-auto" aria-label="Filter by status">
            <option value="">All statuses</option>
            <option value="pending">In lab</option>
            <option value="ready_for_pickup">Ready for pickup</option>
            <option value="delivered">Delivered</option>
            <option value="cancelled">Cancelled</option>
        </select>
        <select x-model="payment" @change="reload()" class="form-select w-auto" aria-label="Filter by payment">
            <option value="">Any payment</option>
            <option value="outstanding">Balance due</option>
            <option value="paid">Fully paid</option>
        </select>
    </div>

    {{-- Results (server-rendered first paint; live layer swaps this innerHTML) --}}
    <div id="ordersResults" x-ref="results" @click="onResultsClick($event)" :class="{ 'results-loading': loading }">
        @include('tenant.orders.partials._results')
    </div>
</div>

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    function ordersLive(cfg) {
        return {
            base: cfg.base,
            q: cfg.q || '',
            status: cfg.status || '',
            payment: cfg.payment || '',
            sort: cfg.sort || 'created_at',
            dir: cfg.dir || 'desc',
            loading: false,
            _ctrl: null,

            // Build a URL from the current control state (resets to page 1).
            reload() {
                const u = new URL(this.base, window.location.origin);
                if (this.q.trim()) u.searchParams.set('q', this.q.trim());
                if (this.status) u.searchParams.set('status', this.status);
                if (this.payment) u.searchParams.set('payment', this.payment);
                u.searchParams.set('sort', this.sort);
                u.searchParams.set('dir', this.dir);
                this.load(u);
            },

            // Fetch the results partial for `url` and swap it in.
            load(url) {
                this.loading = true;
                if (this._ctrl) this._ctrl.abort();
                this._ctrl = new AbortController();

                const fetchUrl = new URL(url);
                fetchUrl.searchParams.set('partial', '1');

                fetch(fetchUrl, { headers: { 'X-Requested-With': 'fetch' }, signal: this._ctrl.signal })
                    .then(r => r.text())
                    .then(html => {
                        this.$refs.results.innerHTML = html;
                        this.loading = false;
                        window.bindAdvanceButtons?.(this.$refs.results);
                        window.hydrateUndoRings?.(this.$refs.results);
                        const clean = new URL(url);
                        clean.searchParams.delete('partial');
                        window.history.replaceState({}, '', clean);
                    })
                    .catch(err => { if (err.name !== 'AbortError') this.loading = false; });
            },

            // Intercept sort headers + pagination links inside the results so they
            // fetch-swap instead of navigating.
            onResultsClick(e) {
                const a = e.target.closest('.pagination a, a[data-sortlink]');
                if (!a) return;
                e.preventDefault();
                const u = new URL(a.href, window.location.origin);
                if (u.searchParams.get('sort')) this.sort = u.searchParams.get('sort');
                if (u.searchParams.get('dir')) this.dir = u.searchParams.get('dir');
                this.load(u);
            },
        };
    }
</script>
@endpush

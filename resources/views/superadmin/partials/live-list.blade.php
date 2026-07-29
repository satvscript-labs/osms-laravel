@once
@push('scripts')
<script nonce="{{ csp_nonce() }}">
/**
 * P6 — ONE live-list engine for every operator list.
 *
 * CLAUDE.md's LIQUID MOTION STANDARD requires list surfaces to filter live via
 * Alpine + a JSON endpoint, debounced ~220ms, with a skeleton state, staggered
 * entrance and the URL kept in sync — "full-page GET reloads for search are not
 * acceptable". Customers already did this; Stores and Billing were still
 * submitting a form and reloading the page.
 *
 * The fix is one factory rather than three copies. Three copies of a debounce,
 * a race guard and a URL sync is three places for the next bug, and they drift:
 * one gets a fix and the other two quietly keep the old behaviour. This holds
 * the TRANSPORT (query, filter, sequencing, history) and the small formatting
 * helpers; each view supplies its own row markup, which is the part that
 * genuinely differs.
 *
 * @param {object} config
 *   endpoint    URL that returns { rows, total, has_more } for Accept: json
 *   query       initial search term (server-rendered state)
 *   filter      initial filter key
 *   filterKey   query-string name for the filter (default 'filter')
 *   serverTotal count behind the server-rendered rows
 *   noun        singular label for the total line, e.g. 'store'
 */
function superadminList(config) {
    return {
        endpoint: config.endpoint,
        query: config.query || '',
        filter: config.filter || 'all',
        filterKey: config.filterKey || 'filter',
        noun: config.noun || 'result',
        rows: [],
        loading: false,
        total: config.serverTotal || 0,
        // Bumped on every result set so Alpine re-runs the `.stagger` entrance
        // rather than silently swapping row text in place.
        listKey: 0,
        // 'idle' shows the server-rendered rows; the first keystroke or filter
        // switches to live results, and it never goes back — going back would
        // mean showing a stale page for a query the operator has moved past.
        mode: 'idle',
        seq: 0,

        setFilter(f) {
            if (this.filter === f) return;
            this.filter = f;
            this.refresh();
        },

        async refresh() {
            this.mode = 'live';
            this.loading = true;
            const mine = ++this.seq;

            const url = new URL(this.endpoint, window.location.origin);
            if (this.query) url.searchParams.set('q', this.query);
            url.searchParams.set(this.filterKey, this.filter);

            // Shareable and bookmarkable, without a navigation — so Back still
            // means "the page before this one", not "my previous keystroke".
            history.replaceState(null, '', url.toString());

            try {
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (mine !== this.seq) return;   // a newer keystroke already won
                this.rows = data.rows || [];
                this.total = data.total || 0;
                this.listKey++;
            } catch (e) {
                if (mine === this.seq) this.rows = [];
            } finally {
                if (mine === this.seq) this.loading = false;
            }
        },

        displayTotal() {
            const n = this.mode === 'idle' ? (config.serverTotal || 0) : this.total;
            return n + ' ' + (n === 1 ? this.noun : this.noun + 's');
        },

        initial(name) { return (name || '?').trim().charAt(0).toUpperCase(); },
        money(v) { return Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 }); },

        /* Lifecycle pill — 03 §6.1's table, in one place so every list shows
           the same state the same way. `access` beats `status`, because a
           customer in grace is technically past_due and calling them that
           would have the operator chasing somebody who still has access. */
        pillLabel(r) {
            if (r.access === 'grace') return 'In grace';
            return { active: 'Active', trialing: 'Trial', past_due: 'Past due', canceled: 'Cancelled' }[r.status] || 'No subscription';
        },
        pillTone(r) {
            if (r.access === 'grace') return 'osms-badge-amber';
            return { active: 'osms-badge-green', trialing: 'osms-badge-blue', past_due: 'osms-badge-amber' }[r.status] || '';
        },
    };
}
</script>
@endpush
@endonce

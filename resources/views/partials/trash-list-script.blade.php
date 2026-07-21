{{-- UX-04 — shared live-search behaviour for the archive (trash) pages.

     Unlike customers/inventory index, this swaps a SERVER-RENDERED partial rather
     than client-rendering JSON rows: every archive row carries CSRF-protected
     restore / force-delete forms, and re-minting those client-side would be
     fragile. The confirm-modal binds via delegation on `document`, so swapped rows
     keep their handlers. --}}
<script nonce="{{ csp_nonce() }}">
    function trashList(config) {
        return {
            endpoint: config.endpoint,
            query: config.query || '',
            loading: false,
            _controller: null,

            refresh() {
                // Abort the in-flight request so a fast typist can't have an older
                // response land after a newer one.
                this._controller?.abort();
                this._controller = new AbortController();
                this.loading = true;

                const url = new URL(this.endpoint, window.location.origin);
                if (this.query) {
                    url.searchParams.set('search', this.query);
                }

                fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: this._controller.signal,
                    credentials: 'same-origin',
                })
                    .then((r) => {
                        if (!r.ok) throw new Error('Request failed');
                        return r.text();
                    })
                    .then((html) => {
                        this.$refs.rows.innerHTML = html;
                        this.loading = false;
                        // Keep the URL shareable and back-button friendly.
                        history.replaceState(null, '', url.searchParams.toString() ? url.toString() : this.endpoint);
                    })
                    .catch((e) => {
                        if (e.name === 'AbortError') return;   // superseded, not an error
                        this.loading = false;
                    });
            },
        };
    }
</script>

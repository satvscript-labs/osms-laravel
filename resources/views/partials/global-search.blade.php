{{-- Global search palette (Cmd+K). Tenant users only. --}}
<div class="modal fade" id="globalSearchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog search-palette">
        <div class="modal-content border-0">
            <div class="modal-body p-0">
                <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom">
                    <i class="bi bi-search text-muted-foreground"></i>
                    <input type="text" id="globalSearchInput" class="form-control border-0 shadow-none"
                           placeholder="Search customers, inventory, orders…" autocomplete="off" data-barcode-target>
                    <kbd class="kbd-chip">Esc</kbd>
                </div>
                <div id="globalSearchResults" class="p-2" style="max-height:60vh;overflow-y:auto;">
                    <p class="text-center text-muted-foreground py-4 mb-0 small">Type to search across your store.</p>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Run after the deferred Vite module has defined window.bootstrap. An inline
// classic script executes during parse — before the deferred ESM bundle — so
// touching `bootstrap` synchronously here would throw and abort the whole IIFE
// (leaving Ctrl/Cmd+K unbound). Wait for DOMContentLoaded instead.
(function () {
    function init() {
    const modalEl = document.getElementById('globalSearchModal');
    if (!modalEl || !window.bootstrap) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const input = document.getElementById('globalSearchInput');
    const out = document.getElementById('globalSearchResults');
    const SEARCH_URL = @json(route('tenant.search'));
    let timer, controller;

    // Ctrl/Cmd+K toggles
    window.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            modal.toggle();
        }
    });
    modalEl.addEventListener('shown.bs.modal', () => input.focus());
    modalEl.addEventListener('hidden.bs.modal', () => { input.value=''; render(null); });

    const money = (n) => '₹ ' + Number(n).toLocaleString('en-IN', {maximumFractionDigits:0});
    const esc = (s) => (s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

    function row(url, icon, left, right) {
        return `<a href="${url}" class="search-result-item d-flex align-items-center gap-2 px-3 py-2 rounded-3 text-decoration-none text-reset">
            <i class="bi ${icon} text-muted-foreground"></i>
            <span class="flex-grow-1 min-w-0 text-truncate">${left}</span>
            <span class="text-muted-foreground text-xs text-nowrap">${right}</span>
            <i class="bi bi-chevron-right person-chevron"></i></a>`;
    }

    function render(data, loading, q) {
        if (loading) { out.innerHTML = '<p class="text-center text-muted-foreground py-4 mb-0 small">Searching…</p>'; return; }
        if (!data) { out.innerHTML = '<p class="text-center text-muted-foreground py-4 mb-0 small">Type to search across your store.</p>'; return; }
        const total = data.customers.length + data.inventory.length + data.orders.length;
        if (total === 0) { out.innerHTML = `<p class="text-center text-muted-foreground py-4 mb-0 small">No results — try a different name, phone, or SKU.</p>`; return; }
        let html = '';
        if (data.customers.length) {
            html += '<p class="section-label px-3 pt-2 mb-1">Customers</p>';
            data.customers.forEach(p => html += row(p.url, 'bi-person', `<span class="fw-medium">${esc(p.name)}</span>`, esc(p.phone)));
        }
        if (data.inventory.length) {
            html += '<p class="section-label px-3 pt-2 mb-1">Inventory</p>';
            data.inventory.forEach(i => html += row(i.url, 'bi-box-seam',
                `<span class="fw-medium">${esc(i.brand)||'—'}</span> <span class="text-muted-foreground">${esc(i.model_name)}</span>`,
                `<span class="font-monospace text-2xs">${esc(i.sku)} · stock ${i.stock_qty}</span>`));
        }
        if (data.orders.length) {
            html += '<p class="section-label px-3 pt-2 mb-1">Orders</p>';
            data.orders.forEach(o => html += row(o.url, 'bi-cart3', `<span class="fw-medium">${esc(o.customer_name)||'—'}</span>`,
                money(o.total_amount) + (o.balance_due > 0 ? ` · <span class="text-danger">${money(o.balance_due)} due</span>` : '')));
        }
        out.innerHTML = html;
    }

    input.addEventListener('input', () => {
        const q = input.value.trim();
        clearTimeout(timer);
        if (controller) controller.abort();
        if (!q) { render(null); return; }
        render(null, true);
        timer = setTimeout(() => {
            controller = new AbortController();
            fetch(`${SEARCH_URL}?q=${encodeURIComponent(q)}`, {headers:{'Accept':'application/json'}, signal: controller.signal})
                .then(r => r.json()).then(d => render(d, false, q)).catch(() => {});
        }, 200);
    });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
@endpush

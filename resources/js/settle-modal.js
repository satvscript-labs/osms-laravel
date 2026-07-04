// Shared "Settle" modal controller (6.1 settle-on-deliver + 6.3 dues settlement).
//
// One modal (resources/views/tenant/orders/partials/_settle-modal.blade.php) is
// reused across the orders board (deliver mode) and the analytics dues list
// (record-only mode). Mirrors the server's resolveDiscount() math so the live
// total/balance preview matches exactly what OrderController::settle() computes.
//
// Exposes window.SettleModal.open(data, opts):
//   data = { id, customer, subtotal, advance, discountType, discountValue }
//   opts = { deliver: bool (default true), onCancel: fn }

const round2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
const fmt = (n) => '₹ ' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function build() {
    const el = document.getElementById('settleModal');
    if (!el || !window.bootstrap) return null;

    const modal = new window.bootstrap.Modal(el);
    const $ = (sel) => el.querySelector(sel);
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content;
    const SETTLE_URL = (id) => `/tenant/orders/${id}/settle`;

    const elTitle = $('[data-settle-title]');
    const elCustomer = $('[data-settle-customer]');
    const elSubtotal = $('[data-settle-subtotal]');
    const elDiscountRow = $('[data-settle-discount-row]');
    const elDiscount = $('[data-settle-discount]');
    const elTotal = $('[data-settle-total]');
    const elPaid = $('[data-settle-paid]');
    const elBalance = $('[data-settle-balance]');
    const amountInput = $('#settle_amount');
    const methodGroup = $('#settleMethods');
    const discToggle = $('#settleDiscountToggle');
    const discPanel = $('#settleDiscountPanel');
    const discChevron = $('[data-settle-chevron]');
    const discTypeGroup = $('#settleDiscountType');
    const discValue = $('#settle_discount_value');
    const errorBox = $('[data-settle-error]');
    const confirmBtn = $('#settleConfirm');
    const confirmLabel = $('[data-settle-confirm-label]');
    const skipBtn = $('#settleSkip');

    let state = null;

    function computeTotals() {
        const sub = state.subtotal;
        const v = Math.max(0, Number(discValue.value) || 0);
        const amt = state.dType === 'percent'
            ? round2(sub * Math.min(v, 100) / 100)
            : round2(Math.min(v, sub));
        const total = round2(sub - amt);
        const balance = round2(total - state.advance);
        return { amt, total, balance };
    }

    function render() {
        const { amt, total, balance } = computeTotals();
        elSubtotal.textContent = fmt(state.subtotal);
        elDiscount.textContent = '− ' + fmt(amt);
        elDiscountRow.classList.toggle('reveal-open', amt > 0);
        elTotal.textContent = fmt(total);
        elPaid.textContent = fmt(state.advance);
        elBalance.textContent = fmt(Math.max(balance, 0));
        elBalance.classList.toggle('text-danger', balance > 0.001);
        elBalance.classList.toggle('text-success', balance <= 0.001);

        if (!state.amountDirty) amountInput.value = Math.max(balance, 0).toFixed(2);

        const underpaid = total < state.advance - 0.001;
        if (underpaid) {
            showError('₹ ' + state.advance.toFixed(2) + ' is already paid — this discount is too large.');
            confirmBtn.disabled = true;
        } else {
            hideError();
            confirmBtn.disabled = false;
        }
    }

    function showError(msg) { errorBox.textContent = msg; errorBox.classList.remove('d-none'); }
    function hideError() { errorBox.classList.add('d-none'); }

    function open(data, opts = {}) {
        const deliver = opts.deliver !== false; // default: deliver mode (6.1)
        const existingType = (data.discountType && data.discountType !== 'none') ? data.discountType : null;
        state = {
            id: data.id,
            subtotal: Number(data.subtotal) || 0,
            advance: Number(data.advance) || 0,
            dType: existingType || 'percent',
            method: 'cash',
            amountDirty: false,
            discountEngaged: false,
            deliver,
            opts,
            committed: false,
        };

        // Copy per-mode (6.1 vs 6.3).
        elTitle.textContent = deliver ? 'Settle & deliver' : 'Settle this balance';
        elCustomer.textContent = data.customer
            ? (deliver ? `Collecting from ${data.customer} before handing over.` : `Record a payment from ${data.customer}.`)
            : 'Record the payment against this order.';
        confirmLabel.textContent = deliver ? 'Settle & mark delivered' : 'Record settlement';
        skipBtn.classList.toggle('d-none', !deliver);

        methodGroup.querySelectorAll('.segmented-item').forEach((b) =>
            b.classList.toggle('active', b.dataset.method === 'cash'));

        discValue.value = existingType ? (Number(data.discountValue) || 0) : '';
        discTypeGroup.querySelectorAll('.segmented-item').forEach((b) =>
            b.classList.toggle('active', b.dataset.dtype === state.dType));
        discPanel.classList.remove('reveal-open');
        discChevron.style.transform = '';
        discToggle.setAttribute('aria-expanded', 'false');

        confirmBtn.disabled = false;
        confirmBtn.querySelector('.spinner-border').classList.add('d-none');
        hideError();
        render();
        modal.show();
    }

    function payload(withSettlement) {
        const body = {};
        if (state.deliver) body.mark_delivered = 1;
        if (withSettlement) {
            const amt = Number(amountInput.value) || 0;
            if (amt > 0) { body.amount = amt; body.method = state.method; }
            if (state.discountEngaged) {
                const v = Math.max(0, Number(discValue.value) || 0);
                body.discount_type = v > 0 ? state.dType : 'none';
                body.discount_value = v;
            }
        }
        return body;
    }

    function submit(withSettlement) {
        confirmBtn.disabled = true;
        const spin = confirmBtn.querySelector('.spinner-border');
        spin.classList.remove('d-none');
        fetch(SETTLE_URL(state.id), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload(withSettlement)),
        }).then(async (res) => {
            if (res.ok) { state.committed = true; window.location.reload(); return; }
            const data = await res.json().catch(() => ({}));
            const msg = data.message || (data.errors ? Object.values(data.errors)[0][0] : 'Something went wrong.');
            showError(msg);
            confirmBtn.disabled = false;
            spin.classList.add('d-none');
        }).catch(() => {
            showError('Network error — please try again.');
            confirmBtn.disabled = false;
            spin.classList.add('d-none');
        });
    }

    // Wire the modal's own controls once.
    methodGroup.querySelectorAll('.segmented-item').forEach((b) => b.addEventListener('click', () => {
        state.method = b.dataset.method;
        methodGroup.querySelectorAll('.segmented-item').forEach((x) => x.classList.toggle('active', x === b));
    }));
    discTypeGroup.querySelectorAll('.segmented-item').forEach((b) => b.addEventListener('click', () => {
        state.dType = b.dataset.dtype;
        state.discountEngaged = true;
        discTypeGroup.querySelectorAll('.segmented-item').forEach((x) => x.classList.toggle('active', x === b));
        render();
    }));
    discToggle.addEventListener('click', () => {
        const isOpen = discPanel.classList.toggle('reveal-open');
        discChevron.style.transform = isOpen ? 'rotate(180deg)' : '';
        discToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (isOpen) { state.discountEngaged = true; discValue.focus(); }
    });
    discValue.addEventListener('input', () => { state.discountEngaged = true; render(); });
    amountInput.addEventListener('input', () => { state.amountDirty = true; });
    confirmBtn.addEventListener('click', () => submit(true));
    skipBtn.addEventListener('click', () => submit(false));

    el.addEventListener('hidden.bs.modal', () => {
        if (state && !state.committed && typeof state.opts.onCancel === 'function') {
            state.opts.onCancel();
        }
    });

    return { open };
}

function init() {
    const controller = build();
    if (controller) window.SettleModal = controller;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

// FT-WhatsApp (Phase 6) — the automated "undo ring".
//
// When an Automated store advances an order, a scheduled message sits in a ~60s
// grace window. The board renders a `.wa-undo` control; this module re-hydrates a
// depleting SVG ring from the message's `data-sends-at` (the DB is the source of
// truth — the ring is cosmetic) and wires the tap-to-revert. `prefers-reduced-
// motion` is honored (no sweep). Idempotent per element, and re-runnable after the
// live table swaps its rows in.

const R = 16;
const CIRC = 2 * Math.PI * R;

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

function complete(btn) {
    clearTimeout(btn._waTimer);
    if (btn._waDone) return;
    btn._waDone = true;
    btn.disabled = true;
    btn.classList.add('wa-undo-done');
    const title = btn.querySelector('.wa-undo-title');
    const sub = btn.querySelector('.wa-undo-sub');
    if (title) title.textContent = 'Message sent';
    if (sub) sub.textContent = 'WhatsApp update on its way';
    // Return the board to its normal actions once the window has closed.
    setTimeout(() => window.location.reload(), 1000);
}

function revert(btn) {
    if (btn.disabled || btn._waDone) return;
    btn.disabled = true;
    clearTimeout(btn._waTimer);
    fetch(btn.dataset.revertUrl, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrf(),
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
    })
        // Whether it succeeds or the grace already elapsed, reload to the truthful state.
        .then(() => window.location.reload())
        .catch(() => window.location.reload());
}

function hydrateUndoRings(root) {
    (root || document).querySelectorAll('.wa-undo').forEach((btn) => {
        if (btn._waBound) return;
        btn._waBound = true;

        const sendsAt = Date.parse(btn.dataset.sendsAt || '');
        const grace = parseInt(btn.dataset.grace || '60', 10);
        const progress = btn.querySelector('.wa-undo-progress');
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const remainingMs = Number.isNaN(sendsAt) ? 0 : Math.max(0, sendsAt - Date.now());
        const frac = grace > 0 ? Math.max(0, Math.min(1, remainingMs / 1000 / grace)) : 0;

        if (progress) {
            progress.style.strokeDasharray = CIRC;
            if (reduce || remainingMs <= 0) {
                progress.style.strokeDashoffset = CIRC * (1 - frac);
            } else {
                // Start at the current remaining fraction, then deplete to empty
                // over the remaining time with a single linear transition.
                progress.style.transition = 'none';
                progress.style.strokeDashoffset = CIRC * (1 - frac);
                progress.getBoundingClientRect(); // force reflow before transitioning
                progress.style.transition = `stroke-dashoffset ${remainingMs / 1000}s linear`;
                progress.style.strokeDashoffset = CIRC;
            }
        }

        btn.addEventListener('click', () => revert(btn));

        if (remainingMs <= 0) {
            complete(btn);
        } else {
            btn._waTimer = setTimeout(() => complete(btn), remainingMs + 300);
        }
    });
}

window.hydrateUndoRings = hydrateUndoRings;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => hydrateUndoRings(document));
} else {
    hydrateUndoRings(document);
}

/**
 * P6 / 03 §6.4 — Back closes the overlay, not the page.
 *
 * The operator panel is modal-heavy by design: every one of the sixteen matrix
 * actions is an overlay. On a phone, Back is the gesture people reach for to
 * dismiss things — and without this, pressing it while "Record payment" is open
 * throws the operator off the customer entirely, losing whatever they had typed
 * and landing them somewhere they did not ask to be.
 *
 * The mechanism: opening an overlay pushes one history entry, so Back has
 * something to consume. Closing it any other way (the button, Escape, a click
 * outside) removes that entry again, so the history stack never accumulates
 * phantom steps that make Back feel broken later.
 *
 * Registered once, globally, on `document` — it therefore covers every Bootstrap
 * modal in the app, including ones written after this file. Wiring it per modal
 * would guarantee the next one shipped without it.
 */

// Set only while a close is being driven BY popstate. Without it the handler
// below would call history.back() for a step the browser has already taken,
// and one Back press would jump two entries.
let closingFromHistory = false;

document.addEventListener('shown.bs.modal', () => {
    history.pushState({ osmsOverlay: true }, '');
});

document.addEventListener('hidden.bs.modal', () => {
    if (closingFromHistory) {
        closingFromHistory = false;
        return;
    }

    // Only unwind an entry WE pushed. If the page has been navigated in the
    // meantime, history.state is somebody else's and must not be touched.
    if (history.state && history.state.osmsOverlay) {
        history.back();
    }
});

window.addEventListener('popstate', () => {
    // `.modal.show` is Bootstrap's own open marker, so this stays correct for
    // stacked overlays: the topmost one closes, and the next Back closes the
    // one beneath it.
    const open = document.querySelector('.modal.show');

    if (!open) return;

    closingFromHistory = true;
    window.bootstrap?.Modal.getInstance(open)?.hide();
});

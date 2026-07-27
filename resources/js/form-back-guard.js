/**
 * Stop the browser Back button landing on a form that was already submitted.
 *
 * A create/edit form is a POST-Redirect-GET: submit the form, land on the new
 * record. Pressing Back then re-shows the create form — filled in, already
 * saved, and one more submit away from a duplicate record. Staff read that as
 * "it didn't save".
 *
 * The server cannot fix this: the form page is a legitimate history entry, and
 * there is no header that removes it. So the form remembers that it was
 * submitted, and if it is ever shown again VIA A BACK/FORWARD NAVIGATION it
 * replaces itself with wherever the user should have gone instead.
 *
 * Opt in per form:
 *     <form method="POST" data-leave-on-back="{{ route('tenant.customers.index') }}">
 *
 * Deliberately narrow:
 *   • only fires for a genuine back/forward navigation, so a validation failure
 *     — which returns to the same URL as a normal navigation — still shows the
 *     form with its errors and the user's input intact;
 *   • uses location.replace(), so the dead form is removed from history rather
 *     than added to it and Back keeps working normally afterwards;
 *   • keyed by pathname, so one form's flag can never redirect a different page.
 */
const KEY = 'osms:submitted:';

const flagFor = () => KEY + window.location.pathname;

const read = (key) => {
    try {
        return window.sessionStorage.getItem(key);
    } catch {
        return null; // private mode / storage disabled — degrade to today's behaviour
    }
};

const write = (key, value) => {
    try {
        window.sessionStorage.setItem(key, value);
    } catch {
        /* ignore */
    }
};

const clear = (key) => {
    try {
        window.sessionStorage.removeItem(key);
    } catch {
        /* ignore */
    }
};

// Capture phase: a form may cancel its own submit, but by then we have already
// recorded the intent, and a flag that is never acted on is cleared below.
document.addEventListener(
    'submit',
    (event) => {
        const form = event.target.closest('form[data-leave-on-back]');
        if (form) {
            write(flagFor(), form.dataset.leaveOnBack);
        }
    },
    true,
);

window.addEventListener('pageshow', (event) => {
    const key = flagFor();
    const target = read(key);

    // `back_forward` is the only navigation type that means "the user pressed
    // Back". A validation failure re-renders this same URL as `navigate`, and
    // must be left alone. `persisted` covers a bfcache restore, which some
    // browsers report without a fresh navigation entry.
    const entry = performance.getEntriesByType('navigation')[0];
    const wentBack = event.persisted || (entry && entry.type === 'back_forward');

    if (!target) {
        return;
    }

    clear(key);

    if (wentBack) {
        window.location.replace(target);
    }
    // Otherwise this is a fresh visit or a validation return: the flag is stale,
    // clearing it above is the whole job.
});

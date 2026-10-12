/**
 * QA-01 / QA-02 — ask for the password BEFORE a panel change is sent, not after.
 *
 * Money and access changes in the operator panel sit behind `password.confirm`
 * (a fresh password every few hours). When that confirmation had lapsed, a
 * submitted form went to "Confirm password" and came back with the change gone,
 * and the live preview read the refusal as "Nothing would change". Both are lies
 * on a money screen.
 *
 * So, on any page that renders the `osms-password-confirm` meta tag:
 *
 *   • a POST/PATCH/DELETE form aimed at /superadmin/… is held back while the
 *     confirmation is stale; a small prompt asks for the password in place, and
 *     the SAME form — with everything already typed into it — is then sent;
 *   • `window.osmsConfirmPassword()` lets other code (the operator-modal preview)
 *     ask for it too, and resolves to true once confirmed.
 *
 * The server stays the authority. If anything slips past this (a script calling
 * form.submit(), a clock that drifted), RequirePasswordConfirmation refuses the
 * write and says plainly that it was not saved.
 */

const META = 'meta[name="osms-password-confirm"]';
const PROTECTED = /^\/superadmin(\/|$)/;

// Re-ask a minute early: a form opened at 2:59:30 must not be sent at 3:00:05.
const MARGIN_SECONDS = 60;

const meta = () => document.querySelector(META);

// The server sends SECONDS REMAINING, not a timestamp, so a skewed device clock
// cannot make a stale confirmation look fresh.
let deadline = 0;

function readDeadline() {
    const m = meta();
    if (!m) return;
    deadline = Date.now() / 1000 + Number(m.getAttribute('content') || 0);
}

const timeout = () => Number(meta()?.dataset.timeout || 10800);

export const needsPasswordConfirm = () => !!meta() && Date.now() / 1000 > deadline - MARGIN_SECONDS;

// ------------------------------------------------------------------
// The prompt
// ------------------------------------------------------------------

let pending = null;

/** Resolves true once the password is confirmed, false if the user backs out. */
export function confirmPassword() {
    if (!needsPasswordConfirm()) return Promise.resolve(true);
    if (pending) return pending; // two callers, one prompt

    pending = new Promise((resolve) => open(resolve)).finally(() => {
        pending = null;
    });

    return pending;
}

function open(resolve) {
    const tpl = document.getElementById('pwConfirmTemplate');
    if (!tpl) {
        resolve(false);
        return;
    }

    const el = tpl.content.firstElementChild.cloneNode(true);

    // Inside the open Bootstrap modal when there is one: its focus trap pulls
    // focus back into the modal, so a prompt outside it could never be typed in.
    const host = document.querySelector('.modal.show') || document.body;
    host.appendChild(el);

    const form = el.querySelector('form');
    const input = el.querySelector('input[type="password"]');
    const error = el.querySelector('[data-pw-error]');
    const submit = el.querySelector('button[type="submit"]');
    let settled = false;

    const close = (ok) => {
        if (settled) return;
        settled = true;
        el.classList.remove('is-open');
        document.removeEventListener('keydown', onKey, true);
        // Let the exit transition play; remove even if it never fires.
        setTimeout(() => el.remove(), 400);
        resolve(ok);
    };

    const onKey = (e) => {
        if (e.key !== 'Escape') return;
        // Escape belongs to the prompt while it is open, not to the modal beneath.
        e.preventDefault();
        e.stopPropagation();
        close(false);
    };
    document.addEventListener('keydown', onKey, true);

    el.querySelectorAll('[data-pw-cancel]').forEach((b) => b.addEventListener('click', () => close(false)));

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        error.textContent = '';
        submit.disabled = true;

        try {
            const res = await fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: new FormData(form),
                // Success is a redirect to wherever they were headed; we do not
                // want to download that page, only to know it was a success.
                redirect: 'manual',
            });

            if (res.type === 'opaqueredirect' || res.ok) {
                deadline = Date.now() / 1000 + timeout();
                close(true);
                return;
            }

            let message = 'That password is not right.';
            if (res.status === 422) {
                const data = await res.json().catch(() => ({}));
                message = data.errors?.password?.[0] || data.message || message;
            } else if (res.status === 419) {
                message = 'This page has been open too long. Reload it, then try again.';
            } else if (res.status === 429) {
                message = 'Too many attempts. Wait a minute, then try again.';
            }
            error.textContent = message;
            input.select();
        } catch {
            error.textContent = 'Could not reach the server. Check the connection and try again.';
        } finally {
            submit.disabled = false;
        }
    });

    // Next frame, so the entrance transition has a starting state to ease from.
    // A timer backs it up: a throttled or background tab may not paint a frame
    // for a while, and the prompt must never sit there invisible but in the way.
    const reveal = () => {
        if (el.classList.contains('is-open') || settled) return;
        el.classList.add('is-open');
        input.focus();
    };
    requestAnimationFrame(reveal);
    setTimeout(reveal, 60);
}

// ------------------------------------------------------------------
// The guard
// ------------------------------------------------------------------

function isProtectedWrite(form) {
    const method = (form.getAttribute('method') || 'get').toLowerCase();
    if (method === 'get') return false;

    // getAttribute, not form.action: an <input name="action"> shadows the property.
    const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);

    return url.origin === window.location.origin && PROTECTED.test(url.pathname);
}

document.addEventListener(
    'submit',
    async (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !isProtectedWrite(form)) return;

        // Our own resubmission after a successful confirm.
        if (form.dataset.pwConfirmed === '1') {
            delete form.dataset.pwConfirmed;
            return;
        }

        if (!needsPasswordConfirm()) return;

        // Capture phase on document: nothing else (Alpine's "submitting…" state,
        // the back-guard flag) sees a submit that is not actually happening.
        e.preventDefault();
        e.stopImmediatePropagation();

        const submitter = e.submitter && e.submitter.form === form ? e.submitter : undefined;

        if (await confirmPassword()) {
            form.dataset.pwConfirmed = '1';
            form.requestSubmit(submitter);
        }
    },
    true,
);

// Once, at load. Deliberately NOT re-read on a back/forward-cache restore: the
// tag still holds the seconds remaining at first render, whereas `deadline`
// (kept in memory with the page) is already the right absolute moment.
readDeadline();

window.osmsConfirmPassword = confirmPassword;
window.osmsNeedsPasswordConfirm = needsPasswordConfirm;

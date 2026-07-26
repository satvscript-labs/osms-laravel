/**
 * Flash-message toasts.
 *
 * Success messages leave on their own after the progress bar drains; errors
 * stay until acknowledged, because something went wrong and the user should be
 * the one to dismiss it.
 *
 * The element is removed only after its exit animation finishes, so it never
 * disappears mid-transition. Nothing here touches page flow — the rail is
 * fixed-position, which is the whole point.
 */
const AUTO_DISMISS_MS = 4500; // must match the toastDrain animation in app.scss

const dismiss = (toast) => {
    if (toast.dataset.leaving) return;
    toast.dataset.leaving = '1';
    toast.classList.add('is-leaving');

    const done = () => toast.remove();
    toast.addEventListener('animationend', done, { once: true });
    // Belt and braces: if the animation is disabled (reduced motion) no
    // animationend ever fires, and the toast would sit there forever.
    setTimeout(done, 600);
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-toast]').forEach((toast) => {
        toast.querySelector('[data-toast-close]')?.addEventListener('click', () => dismiss(toast));

        if (toast.hasAttribute('data-toast-sticky')) return;

        let timer = setTimeout(() => dismiss(toast), AUTO_DISMISS_MS);

        // Hovering pauses the countdown — a message should not vanish while it
        // is being read. The bar visibly stops with it.
        const bar = toast.querySelector('.osms-toast-timer');
        toast.addEventListener('mouseenter', () => {
            clearTimeout(timer);
            if (bar) bar.style.animationPlayState = 'paused';
        });
        toast.addEventListener('mouseleave', () => {
            if (bar) bar.style.animationPlayState = 'running';
            // Whatever is left on the bar is roughly what is left to wait.
            timer = setTimeout(() => dismiss(toast), 1200);
        });
    });
});

{{--
    QA-01/02 — the in-place "confirm your password" prompt.

    Rendered only for the operator. resources/js/password-confirm.js clones this
    template when a panel change is about to be sent with a stale confirmation,
    so the change waits for the password instead of being lost to a redirect.
    Its companion meta tag (seconds remaining) is rendered in the layout <head>.
--}}

<template id="pwConfirmTemplate">
    <div class="pw-prompt" role="dialog" aria-modal="true" aria-labelledby="pwPromptTitle">
        <div class="pw-prompt-card p-4">
            <form method="POST" action="{{ route('password.confirm') }}" novalidate>
                <div class="d-flex align-items-start gap-3 mb-3">
                    <span class="pw-prompt-icon d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0">
                        <i class="bi bi-shield-lock fs-5" aria-hidden="true"></i>
                    </span>
                    <div>
                        <h2 class="h6 fw-semibold font-display mb-1" id="pwPromptTitle">Confirm your password</h2>
                        <p class="text-sm text-muted-foreground mb-0">
                            Money and access changes need your password every few hours.
                            Nothing has been sent yet, and what you typed is kept.
                        </p>
                    </div>
                </div>

                <label class="visually-hidden" for="pwPromptInput">Password</label>
                <input id="pwPromptInput" type="password" name="password" class="form-control"
                       autocomplete="current-password" required placeholder="Your password">
                <p class="text-sm mb-0 mt-2" style="color:var(--tone-red);" data-pw-error aria-live="polite"></p>

                <div class="d-flex gap-2 justify-content-end mt-3">
                    <button type="button" class="btn btn-light" data-pw-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</template>

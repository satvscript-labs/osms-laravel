@php
    /**
     * P5 / matrix row 14 — the password, surfaced exactly once.
     *
     * It arrives in a one-request flash and is gone on the next page load. That
     * is not a limitation to apologise for, it is the design: a secret you can
     * go back and look at is a secret stored somewhere. If the operator loses
     * it they re-issue, which costs one click and leaves an audit entry.
     */
    $credential = session('credential');
@endphp

@if ($credential)
    <div class="card border-0 shadow-sm rounded-4 mb-4 animate-fade-up"
         style="border-left:3px solid var(--tone-amber) !important;"
         x-data="{ copied: false }">
        <div class="card-body p-4">
            <div class="d-flex align-items-start gap-3 mb-3">
                <span class="d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0"
                      style="width:2.5rem;height:2.5rem;background:var(--tone-amber-bg);color:var(--tone-amber);">
                    <i class="bi bi-key-fill"></i>
                </span>
                <div class="min-w-0">
                    <h2 class="h6 fw-semibold font-display mb-1">
                        Password for {{ $credential['name'] }}
                        @if (!empty($credential['store'])) <span class="text-muted-foreground fw-normal">· {{ $credential['store'] }}</span> @endif
                    </h2>
                    <p class="text-muted-foreground text-sm mb-0">
                        Give this to them now. It is not stored anywhere and this page is the only
                        place it will ever appear — leaving or refreshing loses it for good.
                    </p>
                </div>
            </div>

            <p class="text-muted-foreground mb-1 text-xs">Sign in with</p>
            <p class="fw-medium mb-3">{{ $credential['email'] }}</p>

            <p class="text-muted-foreground mb-1 text-xs">Password</p>
            <div class="credential-secret">
                <span class="flex-grow-1" x-ref="secret">{{ $credential['password'] }}</span>
                <button type="button" class="btn btn-light btn-sm flex-shrink-0"
                        @click="navigator.clipboard.writeText($refs.secret.textContent.trim())
                                .then(() => { copied = true; setTimeout(() => copied = false, 2000); })">
                    {{-- No layout shift between states: the label swaps, the button does not resize --}}
                    <span x-show="!copied"><i class="bi bi-clipboard me-1"></i>Copy</span>
                    <span x-show="copied" x-cloak style="color:var(--tone-green);"><i class="bi bi-check2 me-1"></i>Copied</span>
                </button>
            </div>
        </div>
    </div>
@endif

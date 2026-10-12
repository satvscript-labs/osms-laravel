@php
    /**
     * P5 — the band that makes a view-as session impossible to mistake for your own.
     *
     * `$impersonation` is shared by ReadOnlyImpersonation middleware, so this
     * renders on EVERY page of the impersonated session, not only the ones we
     * remembered. It is sticky and full-bleed on purpose: an operator who
     * scrolled past a dismissible notice and then wondered why a button did
     * nothing is the failure this prevents.
     */
    $state = $impersonation ?? null;
@endphp

@if ($state)
    <div class="impersonation-band no-print" role="status"
         x-data="impersonationBand({ expiresAt: @js($state['expires_at']) })">
        <i class="bi bi-eye-fill flex-shrink-0" aria-hidden="true"></i>

        <div class="flex-grow-1 min-w-0">
            <span class="fw-semibold">Viewing {{ $state['store_name'] }} — read-only.</span>
            <span class="d-none d-sm-inline text-2xs opacity-75 ms-1">
                Signed in as {{ $state['target_email'] }}. Nothing you do here can change their data.
            </span>
        </div>

        {{-- The clock is the point: it is running down whether or not you look. --}}
        <span class="impersonation-clock font-monospace flex-shrink-0" x-text="remaining" aria-live="off"></span>

        <form method="POST" action="{{ route('impersonation.stop') }}" class="m-0 flex-shrink-0">
            @csrf
            <button type="submit" class="btn btn-sm btn-light">
                <i class="bi bi-box-arrow-left me-1" aria-hidden="true"></i>Leave
            </button>
        </form>
    </div>

    @push('scripts')
    <script nonce="{{ csp_nonce() }}">
    function impersonationBand(config) {
        return {
            remaining: '',
            init() {
                const ends = new Date(config.expiresAt).getTime();
                const tick = () => {
                    const left = Math.max(0, Math.floor((ends - Date.now()) / 1000));
                    const m = String(Math.floor(left / 60)).padStart(2, '0');
                    const s = String(left % 60).padStart(2, '0');
                    this.remaining = `${m}:${s}`;
                    // At zero the middleware ends the session on the next
                    // request. Reloading makes that immediate rather than
                    // leaving a dead band on screen until they click something.
                    if (left === 0) { clearInterval(this._i); window.location.reload(); }
                };
                tick();
                this._i = setInterval(tick, 1000);
            },
            destroy() { clearInterval(this._i); },
        };
    }
    </script>
    @endpush
@endif

@props([
    'id',                       // unique dom id
    'title',
    'action',                   // form post target
    'method' => 'POST',
    'label' => 'Confirm',       // primary button text
    'dismiss' => 'Cancel',      // secondary button text — override when the ACTION is
                                // itself called "Cancel", or you get two buttons
                                // labelled Cancel meaning opposite things
    'tone' => 'primary',        // primary | danger
    'icon' => 'bi-sliders',
    'preview' => null,          // lifecycle action name to live-preview, or null
    'account' => null,          // account id, required when $preview is set
    'intro' => null,
])

{{--
    THE canonical operator overlay (P3, and the fix for extract finding E-2).

    Every one of the 16 dual-lane actions renders through this one component
    rather than its own inline Bootstrap modal. Two payoffs:

      1. "Quote before charge" is built in — when $preview is set, the modal
         asks the server what the action WOULD do (running the real code in a
         rolled-back transaction) and shows the before → after before the
         operator can commit. One implementation, so no action can ship
         without its preview.
      2. When the responsive foundation lands, converting every operator
         action to a bottom sheet on phones is a change to THIS FILE, not to
         sixteen call sites.

    Slot = the form fields. The footer, preview panel and submit are ours.
--}}

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-title" aria-hidden="true"
     x-data="operatorModal({
        id: @js($id),
        previewAction: @js($preview),
        previewUrl: @js($account ? route('superadmin.accounts.preview', $account) : null),
     })">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ $action }}" class="modal-content rounded-4 border-0"
              style="box-shadow: var(--shadow-overlay);" @submit="submitting = true">
            @csrf
            @if ($method !== 'POST') @method($method) @endif
            @if ($preview) <input type="hidden" name="action" value="{{ $preview }}"> @endif

            <div class="modal-header border-0 pb-0 px-4 pt-4">
                <div class="d-flex align-items-center gap-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0"
                          style="width:2.5rem;height:2.5rem;
                                 background:{{ $tone === 'danger' ? 'var(--tone-red-bg)' : 'var(--osms-primary-soft)' }};
                                 color:{{ $tone === 'danger' ? 'var(--tone-red)' : 'var(--osms-primary)' }};">
                        <i class="bi {{ $icon }}"></i>
                    </span>
                    <h2 class="h6 fw-semibold font-display mb-0" id="{{ $id }}-title">{{ $title }}</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body px-4 pt-3">
                @if ($intro)
                    <p class="text-muted-foreground text-sm mb-3">{{ $intro }}</p>
                @endif

                {{ $slot }}

                {{-- ---- Live "this will…" panel ----
                     Motion contract (CLAUDE.md): nothing toggles on/off. Once
                     this panel has appeared it STAYS — it never unmounts and
                     re-mounts per keystroke. While a new quote is in flight the
                     previous answer stays on screen, dimmed, rather than being
                     replaced by a skeleton; only the very first quote shows one.
                     Getting this wrong is what made it flicker on every key. --}}
                @if ($preview)
                    <div class="mt-3 p-3 rounded-3" style="background:var(--surface-sunken);"
                         x-show="settled || loading" x-cloak x-transition.opacity>
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <p class="section-label mb-0">This will</p>
                            {{-- A quiet re-quoting hint: no layout shift, no content swap. --}}
                            <span class="spinner-border spinner-border-sm text-primary" role="status"
                                  aria-hidden="true" x-show="loading && settled" x-cloak
                                  style="width:.7rem;height:.7rem;border-width:.12em;"></span>
                        </div>

                        {{-- First quote only: a skeleton, because there is nothing to keep. --}}
                        <div x-show="loading && !settled" x-cloak>
                            <div class="skeleton mb-2" style="height:.7rem;width:70%;"></div>
                            <div class="skeleton" style="height:.7rem;width:45%;"></div>
                        </div>

                        {{-- The answer. Dims while a newer one is being fetched. --}}
                        <div x-show="settled" x-cloak
                             :style="loading ? 'opacity:.55;transition:opacity var(--duration-base) var(--ease-out);' : 'opacity:1;transition:opacity var(--duration-base) var(--ease-out);'">
                            <p class="mb-0 text-sm" style="color:var(--tone-red);" x-show="error" x-cloak x-text="error"></p>

                            {{-- AUD-06 — one deliberate decision must never quietly
                                 erase another. Not an error; it does not block. --}}
                            <template x-for="w in warnings" :key="w">
                                <p class="mb-2 text-sm d-flex align-items-start gap-2" style="color:var(--tone-amber);">
                                    <i class="bi bi-exclamation-triangle flex-shrink-0" style="margin-top:.15rem;"></i>
                                    <span x-text="w"></span>
                                </p>
                            </template>

                            <template x-if="!error">
                                <div>
                                    <template x-for="r in rows" :key="r.label">
                                        <div class="d-flex justify-content-between align-items-baseline gap-3 py-1 text-sm">
                                            <span class="text-muted-foreground" x-text="r.label"></span>
                                            <span class="text-end">
                                                <span class="text-faint text-decoration-line-through"
                                                      x-show="r.from !== null && r.from !== ''" x-text="r.from"></span>
                                                <span class="fw-semibold ms-1" x-text="r.to"></span>
                                            </span>
                                        </div>
                                    </template>

                                    <p class="mb-0 mt-2 text-xs text-muted-foreground" x-show="ledger > 0" x-cloak>
                                        <i class="bi bi-receipt me-1"></i>
                                        <span x-text="ledger"></span> ledger entry will be added.
                                    </p>

                                    <p class="mb-0 text-sm text-muted-foreground" x-show="!rows.length" x-cloak>
                                        Nothing would change.
                                    </p>
                                </div>
                            </template>
                        </div>
                    </div>
                @endif
            </div>

            <div class="modal-footer border-0 px-4 pb-4 pt-2 d-flex gap-2">
                <button type="button" class="btn btn-light flex-grow-1 flex-sm-grow-0" data-bs-dismiss="modal">{{ $dismiss }}</button>
                <button type="submit"
                        class="btn {{ $tone === 'danger' ? 'btn-danger' : 'btn-primary' }} flex-grow-1 flex-sm-grow-0"
                        :disabled="submitting || (previewAction && settled && !!error)">
                    <span x-show="!submitting">{{ $label }}</span>
                    <span x-show="submitting" x-cloak>
                        <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Working…
                    </span>
                </button>
            </div>
        </form>
    </div>
</div>

@once
@push('scripts')
<script nonce="{{ csp_nonce() }}">
/**
 * Drives the shared operator overlay: debounced live preview + submit guard.
 * Registered once however many modals a page renders.
 */
function operatorModal(config) {
    return {
        previewAction: config.previewAction,
        previewUrl: config.previewUrl,
        rows: [],
        warnings: [],
        ledger: 0,
        error: null,
        loading: false,
        // `settled` = at least one quote has come back, so there is something
        // real to show. Until then the panel must never claim "nothing would
        // change" — it simply does not know yet. Once true it stays true for
        // the life of the open modal, which is what stops the flicker.
        settled: false,
        submitting: false,
        seq: 0,

        init() {
            if (!this.previewAction || !this.previewUrl) return;

            const root = this.$el;

            // Re-quote whenever any field changes — the operator should never
            // have to guess what a different amount or date would do.
            root.addEventListener('input', () => this.schedule());
            root.addEventListener('change', () => this.schedule());

            // Quote once on open, so the panel is populated before they touch
            // anything (many actions need no input at all).
            root.addEventListener('shown.bs.modal', () => this.refresh());
            root.addEventListener('hidden.bs.modal', () => {
                // Full reset on close so the next open re-quotes from scratch
                // rather than showing a stale answer for the old inputs.
                clearTimeout(this._t);
                this.seq++;                 // orphan any in-flight response
                this.rows = []; this.warnings = []; this.ledger = 0; this.error = null;
                this.settled = false; this.loading = false; this.submitting = false;
            });
        },

        schedule() {
            // Deliberately does NOT reveal the panel or clear the current
            // answer — the previous quote stays readable until the new one
            // lands. Only `refresh()` ever changes what is displayed.
            clearTimeout(this._t);
            this._t = setTimeout(() => this.refresh(), 220);
        },

        async refresh() {
            const form = this.$el.querySelector('form');
            if (!form) return;

            const mine = ++this.seq;
            this.loading = true;

            try {
                const res = await fetch(this.previewUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form),
                });
                const data = await res.json();
                if (mine !== this.seq) return; // a newer keystroke already won

                this.error = data.error || null;
                this.warnings = data.warnings || [];
                this.ledger = data.ledger_rows_added || 0;
                this.rows = Object.entries(data.changes || {}).map(([key, v]) => ({
                    label: this.humanise(key),
                    from: this.format(key, v.from),
                    to: this.format(key, v.to),
                }));
                this.settled = true;
            } catch (e) {
                if (mine === this.seq) {
                    this.error = 'Could not calculate the outcome.';
                    this.settled = true;
                }
            } finally {
                if (mine === this.seq) this.loading = false;
            }
        },

        humanise(key) {
            return ({
                status: 'Status',
                access: 'Access',
                interval: 'Billing',
                current_period_end: 'Covered until',
                cancel_at_period_end: 'Cancels at period end',
                negotiated_price: 'Negotiated price',
                effective_price: 'They pay',
                override_kind: 'Operator override',
                override_until: 'Override until',
            })[key] || key.replace(/_/g, ' ');
        },

        format(key, v) {
            if (v === null || v === undefined || v === '') return '—';
            if (v === true) return 'yes';
            if (v === false) return 'no';
            if (key === 'effective_price' || key === 'negotiated_price') {
                return '₹ ' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 2 });
            }
            return String(v);
        },
    };
}
</script>
@endpush
@endonce

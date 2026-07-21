{{--
    Reusable confirm-action modal (premium replacement for window.confirm).
    Trigger from any element inside a <form>:
      <button type="button" data-confirm="Message shown in the dialog body"
              data-confirm-title="Delete item" data-confirm-label="Delete"
              data-confirm-tone="danger">…</button>
    On confirm, the element's closest <form> is submitted.
--}}
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0" style="box-shadow: var(--shadow-overlay);">
            <div class="modal-body p-4 text-center">
                <span id="confirmModalIcon"
                      class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                      style="width:3rem;height:3rem;background:var(--tone-red-bg);color:var(--tone-red);">
                    <i class="bi bi-exclamation-triangle fs-4"></i>
                </span>
                <h2 class="h5 fw-semibold font-display mb-2" id="confirmModalTitle">Are you sure?</h2>
                <p class="text-muted-foreground mb-4" id="confirmModalBody">This action cannot be undone.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmModalConfirm">Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    // Mirrors the global-search deferred-ESM guard: wait for DOMContentLoaded so
    // window.bootstrap (from the bundled module) exists before instantiating the modal.
    (function () {
        function init() {
            const modalEl = document.getElementById('confirmModal');
            if (!modalEl || !window.bootstrap) return;

            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            const bodyEl = document.getElementById('confirmModalBody');
            const titleEl = document.getElementById('confirmModalTitle');
            const confirmBtn = document.getElementById('confirmModalConfirm');
            let targetForm = null;

            document.addEventListener('click', (e) => {
                const trigger = e.target.closest('[data-confirm]');
                if (!trigger) return;
                e.preventDefault();

                targetForm = trigger.closest('form');
                bodyEl.textContent = trigger.dataset.confirm || 'This action cannot be undone.';
                titleEl.textContent = trigger.dataset.confirmTitle || 'Are you sure?';
                confirmBtn.textContent = trigger.dataset.confirmLabel || 'Confirm';
                modal.show();
            });

            confirmBtn.addEventListener('click', () => {
                if (targetForm) { targetForm.submit(); }
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    })();
</script>
@endpush

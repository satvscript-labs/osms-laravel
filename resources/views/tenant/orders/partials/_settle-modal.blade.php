{{-- Shared "Settle" modal (6.1 settle-on-deliver + 6.3 dues settlement).
     Driven by window.SettleModal (resources/js/settle-modal.js): the orders board
     opens it in deliver mode; the analytics dues list opens it in record-only mode. --}}
<div class="modal fade" id="settleModal" tabindex="-1" aria-labelledby="settleTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0" style="box-shadow: var(--shadow-overlay);">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h2 class="h5 fw-semibold font-display mb-1" id="settleTitle" data-settle-title>Settle &amp; deliver</h2>
                    <p class="text-muted-foreground mb-0 text-xs" data-settle-customer>Collect the balance before handing over.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 d-flex flex-column gap-3">
                {{-- Live money summary --}}
                <div class="rounded-3 p-3 d-flex flex-column gap-1" style="background: var(--surface-sunken);">
                    <div class="d-flex justify-content-between text-sm">
                        <span class="text-muted-foreground">Subtotal</span>
                        <span class="font-monospace" data-settle-subtotal>₹ 0</span>
                    </div>
                    <div class="d-flex justify-content-between text-sm reveal" data-settle-discount-row>
                        <span class="reveal-inner text-success">Discount</span>
                        <span class="reveal-inner font-monospace text-success" data-settle-discount>− ₹ 0</span>
                    </div>
                    <div class="d-flex justify-content-between text-sm">
                        <span class="text-muted-foreground">Total</span>
                        <span class="font-monospace fw-medium" data-settle-total>₹ 0</span>
                    </div>
                    <div class="d-flex justify-content-between text-sm">
                        <span class="text-muted-foreground">Already paid</span>
                        <span class="font-monospace" data-settle-paid>₹ 0</span>
                    </div>
                    <div class="d-flex justify-content-between border-top pt-1 mt-1 fw-semibold">
                        <span>Balance due</span>
                        <span class="font-monospace" data-settle-balance>₹ 0</span>
                    </div>
                </div>

                {{-- Amount collected --}}
                <div>
                    <label for="settle_amount" class="form-label small fw-medium mb-2">Amount collecting now (₹)</label>
                    <input id="settle_amount" type="number" step="0.01" min="0" class="form-control" inputmode="decimal">
                    <div class="form-text text-2xs">Prefilled to the balance. Anything above it is automatically capped.</div>
                </div>

                {{-- Payment method (segmented) --}}
                <div>
                    <label class="form-label small fw-medium mb-2">Payment method</label>
                    <div class="segmented w-100 d-flex" role="group" id="settleMethods">
                        <button type="button" class="segmented-item flex-fill justify-content-center active" data-method="cash"><i class="bi bi-cash"></i> Cash</button>
                        <button type="button" class="segmented-item flex-fill justify-content-center" data-method="card"><i class="bi bi-credit-card"></i> Card</button>
                        <button type="button" class="segmented-item flex-fill justify-content-center" data-method="upi"><i class="bi bi-phone"></i> UPI</button>
                        <button type="button" class="segmented-item flex-fill justify-content-center" data-method="other"><i class="bi bi-three-dots"></i> Other</button>
                    </div>
                </div>

                {{-- Last-moment discount (collapsed) --}}
                <div>
                    <button type="button" class="btn btn-light btn-sm w-100 d-flex align-items-center justify-content-between"
                            id="settleDiscountToggle" aria-expanded="false">
                        <span><i class="bi bi-tag me-1"></i> Add a last-moment discount</span>
                        <i class="bi bi-chevron-down" data-settle-chevron style="transition: transform var(--duration-base) var(--ease-spring);"></i>
                    </button>
                    <div class="reveal mt-2" id="settleDiscountPanel">
                        <div class="reveal-inner">
                            <div class="d-flex gap-2 pt-1">
                                <div class="segmented" role="group" id="settleDiscountType">
                                    <button type="button" class="segmented-item active" data-dtype="amount">₹</button>
                                    <button type="button" class="segmented-item" data-dtype="percent">%</button>
                                </div>
                                <input id="settle_discount_value" type="number" step="0.01" min="0" class="form-control form-control-sm"
                                       placeholder="0" inputmode="decimal">
                            </div>
                            <div class="form-text text-2xs">Applied to the order total before the balance is recomputed.</div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-danger py-2 px-3 mb-0 text-xs d-none" data-settle-error role="alert"></div>
            </div>
            <div class="modal-footer border-0 pt-0 flex-column align-items-stretch gap-2">
                <button type="button" class="btn btn-primary w-100" id="settleConfirm">
                    <span class="spinner-border spinner-border-sm d-none me-1" role="status"></span>
                    <i class="bi bi-check-lg me-1"></i> <span data-settle-confirm-label>Settle &amp; mark delivered</span>
                </button>
                <button type="button" class="btn btn-link btn-sm text-muted-foreground text-decoration-none w-100" id="settleSkip"
                        data-settle-skip>
                    Mark delivered without settling
                </button>
            </div>
        </div>
    </div>
</div>

{{--
    E3 / FB-04 — money received online that is on no bill. Kept, never dropped,
    never silently applied: a person decides. A payment we can tie to a customer
    is applied on THEIR page (previewed); an unknown one is first matched to a
    customer here. Refunds happen in Razorpay and are recorded here.
--}}
@if (($unapplied ?? collect())->isNotEmpty())
    <div id="unapplied" class="mb-4 animate-fade-up">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <p class="section-label mb-0">Received online · not on any bill</p>
            <span class="osms-badge osms-badge-amber"><span class="osms-badge-dot"></span>{{ $unapplied->count() }}</span>
        </div>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden" style="border-left:3px solid var(--tone-amber) !important;">
            @foreach ($unapplied as $u)
                {{-- Wraps on a phone: the buttons drop below the text instead of squeezing it to one letter wide. --}}
                <div class="person-row flex-wrap" style="cursor:default;">
                    <span class="person-avatar"><i class="bi bi-inbox"></i></span>
                    <div class="flex-grow-1 min-w-0" style="flex-basis:12rem;">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="fw-semibold">₹ {{ number_format($u->amount, 2) }}</span>
                            @if ($u->account)
                                <a href="{{ route('superadmin.accounts.show', $u->account) }}#tab-owed" class="meta-chip">{{ $u->account->displayName() }}</a>
                            @else
                                <span class="meta-chip">Customer unknown</span>
                            @endif
                        </div>
                        <div class="text-muted-foreground text-sm mt-1">{{ $u->reason }}</div>
                        <div class="text-xs text-faint mt-1 font-monospace">
                            {{ $u->gateway_payment_id }}@if ($u->gateway_order_id) · {{ $u->gateway_order_id }}@endif · {{ ($u->paid_at ?? $u->created_at)->format('d M Y, H:i') }}
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 ms-auto">
                        @if ($u->account)
                            <a href="{{ route('superadmin.accounts.show', $u->account) }}#tab-owed" class="btn btn-primary btn-sm">Apply…</a>
                            {{-- E5-B — matched to the wrong customer? Move it while it is still open. --}}
                            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#m-rematch-{{ $u->id }}">Wrong customer?</button>
                        @else
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#m-assign-{{ $u->id }}">Whose is it?</button>
                        @endif
                        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#m-refunded-{{ $u->id }}">Refunded</button>
                    </div>
                </div>

                @if ($u->account)
                    <x-operator-modal :id="'m-rematch-' . $u->id" title="Move to another customer"
                        :action="route('superadmin.unapplied.rematch', $u->id)"
                        label="Move it" icon="bi-arrow-left-right"
                        intro="₹ {{ number_format($u->amount, 2) }} is on {{ $u->account->displayName() }}. If it is someone else's, move it — then apply it on their page.">
                        <label class="form-label" for="rm-acc-{{ $u->id }}">Customer</label>
                        <select id="rm-acc-{{ $u->id }}" name="account" class="form-select" required>
                            <option value="">Choose…</option>
                            @foreach ($customers as $c)
                                @continue($c->id === $u->account_id)
                                <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                            @endforeach
                        </select>
                        <label class="form-label mt-3" for="rm-note-{{ $u->id }}">Why <span style="color:var(--tone-red);">*</span></label>
                        <input id="rm-note-{{ $u->id }}" name="note" type="text" required maxlength="500" class="form-control"
                               placeholder="e.g. same surname — the receipt is from the other shop">
                    </x-operator-modal>
                @endif

                @unless ($u->account)
                    <x-operator-modal :id="'m-assign-' . $u->id" title="Whose payment is this?"
                        :action="route('superadmin.unapplied.assign', $u->id)"
                        label="Match to customer" icon="bi-person-check"
                        intro="₹ {{ number_format($u->amount, 2) }} arrived for an order OSMS has no bill for. Say whose it is; then apply it to one of their bills on their page.">
                        <label class="form-label" for="as-acc-{{ $u->id }}">Customer</label>
                        <select id="as-acc-{{ $u->id }}" name="account" class="form-select" required>
                            <option value="">Choose…</option>
                            @foreach ($customers as $c)
                                <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                            @endforeach
                        </select>
                        <label class="form-label mt-3" for="as-note-{{ $u->id }}">How you know <span style="color:var(--tone-red);">*</span></label>
                        <input id="as-note-{{ $u->id }}" name="note" type="text" required maxlength="500" class="form-control"
                               placeholder="e.g. customer sent the Razorpay receipt">
                    </x-operator-modal>
                @endunless

                <x-operator-modal :id="'m-refunded-' . $u->id" title="Record a refund"
                    :action="route('superadmin.unapplied.refunded', $u->id)"
                    label="Record refund" dismiss="Never mind" icon="bi-arrow-return-left" tone="danger"
                    intro="Refund ₹ {{ number_format($u->amount, 2) }} in the Razorpay dashboard first, then record it here. Nothing changes for the customer's access.">
                    <label class="form-label" for="rf-note-{{ $u->id }}">Refund reference <span style="color:var(--tone-red);">*</span></label>
                    <input id="rf-note-{{ $u->id }}" name="note" type="text" required maxlength="500" class="form-control"
                           placeholder="e.g. rfnd_ABC123 — paid twice">
                </x-operator-modal>
            @endforeach
        </div>
    </div>
@endif

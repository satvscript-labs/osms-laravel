{{--
    P3 — every commercial action for one account, each in the shared overlay.
    Rendered once at the foot of the 360; triggers live in the header and tabs,
    so every matrix row is reachable in ≤3 clicks from the customer's screen.
--}}
@php
    $act = route('superadmin.accounts.action', $account);
    $liveEnd = $subscription?->current_period_end?->format('d M Y') ?? '—';
@endphp

{{-- ── Rows 4 + 5 · Record a payment (D-5) ──────────────────────────
     ONE money lever. It asks what the money pays for — an open bill, or their
     next renewal (N months/years ahead, E2-A) — and goes through Settlement, so
     the ledger row names its charge and the clock moves only when time is bought.
     Previewed like every other money action (FB-08). Below the bill: a recorded
     one-off discount. Above it: an amber warning and a box the operator must tick
     (D-4) — the server refuses the over-payment without it. --}}
@php
    $openBills = ($charges ?? collect())->where('status', 'pending')->values();
    $billList = $openBills->map(fn ($c) => ['id' => $c->id, 'amount' => (float) $c->amount])->all();
    $payInterval = ($subscription?->pending_interval ?? $subscription?->interval) === 'yearly' ? 'yearly' : 'monthly';
@endphp
<x-operator-modal id="m-payment" title="Record a payment"
    :action="route('superadmin.accounts.payment', $account)" preview="record_payment" :account="$account"
    label="Record payment" icon="bi-cash-coin"
    intro="Money that arrived outside the gateway — cash, UPI, a bank transfer. Say what it pays for; the preview shows exactly what it will do.">
    <div class="row g-3"
         x-data="paymentField(@js($billList), @js($renewPrices ?? ['monthly' => 0, 'yearly' => 0]), @js($payInterval), @js($openBills->first()?->id ?? 'renewal'))">
        {{-- A-1 — the renewal this form was opened for; a stale page is refused. --}}
        <input type="hidden" name="renews_from" value="{{ $subscription?->current_period_end?->toDateString() ?? 'none' }}">
        <div class="col-12">
            <label class="form-label" for="pay-for">What it pays for</label>
            <select id="pay-for" name="for" class="form-select" x-model="forId">
                @foreach ($openBills as $bill)
                    <option value="{{ $bill->id }}">{{ $bill->number }} · {{ $bill->label() }} — ₹ {{ number_format($bill->amount, 2) }}</option>
                @endforeach
                <option value="renewal">Their next renewal</option>
            </select>
        </div>

        {{-- The next term: which period, and how far ahead. Revealed, never popped. --}}
        <div class="col-12" x-show="isRenewal" x-transition.opacity>
            <div class="row g-3">
                <div class="col-6">
                    <label class="form-label" for="pay-interval">Period</label>
                    <select id="pay-interval" name="interval" class="form-select" x-model="interval">
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label" for="pay-cycles"
                           x-text="interval === 'yearly' ? 'Years ahead' : 'Months ahead'">Months ahead</label>
                    <input id="pay-cycles" name="cycles" type="number" min="1" :max="maxCycles"
                           x-model.number="cycles" class="form-control" inputmode="numeric">
                </div>
            </div>
        </div>

        <div class="col-6">
            <label class="form-label" for="pay-amount">Amount</label>
            <div class="input-group">
                <span class="input-group-text">₹</span>
                <input id="pay-amount" name="amount" type="number" step="0.01" min="0" required
                       x-model.number="amount" @input="touched = true"
                       class="form-control" inputmode="decimal">
            </div>
            <p class="text-xs text-muted-foreground mt-1 mb-0">Bill: ₹ <span x-text="billLabel"></span></p>
        </div>
        <div class="col-6">
            <label class="form-label" for="pay-method">Method</label>
            <select id="pay-method" name="method" class="form-select" required>
                <option value="cash">Cash</option>
                <option value="upi">UPI</option>
                <option value="bank_transfer">Bank transfer</option>
                <option value="cheque">Cheque</option>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label" for="pay-date">Received on</label>
            <input id="pay-date" name="paid_at" type="date" class="form-control"
                   value="{{ now(config('billing.timezone'))->toDateString() }}"
                   max="{{ now(config('billing.timezone'))->toDateString() }}">
        </div>
        <div class="col-6">
            <label class="form-label" for="pay-ref">Reference <span class="text-faint">optional</span></label>
            <input id="pay-ref" name="reference" type="text" class="form-control" placeholder="UPI ref, cheque no…">
        </div>
        <div class="col-12">
            <label class="form-label" for="pay-note">Note <span class="text-faint">optional</span></label>
            <input id="pay-note" name="reason" type="text" class="form-control" maxlength="500">
        </div>

        {{-- REQ-15 — the counter-bargain, against THIS bill only. --}}
        <div class="col-12" x-show="discounted" x-cloak x-transition.opacity>
            <div class="rounded-3 p-3" style="background:var(--tone-green-bg);">
                <p class="text-sm mb-2" style="color:var(--tone-green);">
                    <i class="bi bi-tag me-1"></i>
                    <strong>₹ <span x-text="saved"></span></strong> below the bill of ₹ <span x-text="billLabel"></span> —
                    recorded as a discount on this payment only, not on their ongoing rate.
                </p>
                <label class="form-label" for="pay-discount-why">Why the discount <span class="text-faint">optional</span></label>
                <input id="pay-discount-why" name="discount_reason" type="text" class="form-control" maxlength="500"
                       placeholder="e.g. bargained at the counter, rounded down">
            </div>
        </div>

        {{-- D-4 / FB-09 — more than the bill is never silent. --}}
        <div class="col-12" x-show="over" x-cloak x-transition.opacity>
            <div class="rounded-3 p-3" style="background:var(--tone-amber-bg);">
                <p class="text-sm mb-2" style="color:var(--tone-amber);">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>₹ <span x-text="excess"></span></strong> MORE than the bill of ₹ <span x-text="billLabel"></span>.
                    To pay for more time, choose more months instead.
                </p>
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" name="confirm_over" value="1" id="pay-confirm-over" x-model="confirmed">
                    <label class="form-check-label text-sm" for="pay-confirm-over">I confirm this amount — it is what they paid</label>
                </div>
            </div>
        </div>
    </div>
</x-operator-modal>

{{-- ── Row 3 · Extend ───────────────────────────────────────────── --}}
<x-operator-modal id="m-extend" title="Extend" :action="$act" preview="extend" :account="$account"
    label="Extend" icon="bi-hourglass-split"
    intro="Add days without taking payment. Extends from {{ $liveEnd }}, never shortening what they have.">
    <div class="row g-3">
        <div class="col-5">
            <label class="form-label" for="ex-days">Days</label>
            <input id="ex-days" name="days" type="number" min="1" max="365" value="14" required
                   class="form-control" inputmode="numeric">
        </div>
        <div class="col-7">
            <label class="form-label" for="ex-reason">Why <span style="color:var(--tone-red);">*</span></label>
            <input id="ex-reason" name="reason" type="text" required maxlength="500"
                   class="form-control" placeholder="e.g. setup delay on our side">
        </div>
    </div>
</x-operator-modal>

{{-- ── Row 6 · Comp ─────────────────────────────────────────────── --}}
<x-operator-modal id="m-comp" title="Grant free access" :action="$act" preview="comp" :account="$account"
    label="Grant" icon="bi-gift"
    intro="Free months, recorded as a ₹0 entry on the ledger so lifetime value stays honest.">
    <div class="row g-3">
        <div class="col-5">
            <label class="form-label" for="cp-months">Months</label>
            <input id="cp-months" name="months" type="number" min="1" max="60" value="1" required
                   class="form-control" inputmode="numeric">
        </div>
        <div class="col-7">
            <label class="form-label" for="cp-reason">Why <span style="color:var(--tone-red);">*</span></label>
            <input id="cp-reason" name="reason" type="text" required maxlength="500"
                   class="form-control" placeholder="e.g. goodwill after the outage">
        </div>
    </div>
</x-operator-modal>

{{-- ── Row 7 · Price ────────────────────────────────────────────── --}}
<x-operator-modal id="m-price" title="Set a negotiated price" :action="$act" preview="set_price" :account="$account"
    label="Set price" icon="bi-tag"
    intro="What this customer actually pays, overriding the list price until you clear it.">
    <div class="row g-3">
        <div class="col-5">
            <label class="form-label" for="pr-price">Price</label>
            <div class="input-group">
                <span class="input-group-text">₹</span>
                <input id="pr-price" name="price" type="number" step="0.01" min="0" required
                       value="{{ $subscription?->negotiated_price }}" class="form-control" inputmode="decimal">
            </div>
        </div>
        <div class="col-7">
            <label class="form-label" for="pr-reason">Why <span style="color:var(--tone-red);">*</span></label>
            <input id="pr-reason" name="reason" type="text" required maxlength="500"
                   class="form-control" placeholder="e.g. first customer rate">
        </div>
    </div>
</x-operator-modal>

@if ($subscription?->hasNegotiatedPrice())
<x-operator-modal id="m-clear-price" title="Back to list price" :action="$act" preview="clear_price" :account="$account"
    label="Clear negotiated price" dismiss="Never mind" icon="bi-tag" tone="danger"
    intro="Removes the bespoke price. They will pay the plan's list price from the next renewal.">
    <label class="form-label" for="cpz-reason">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="cpz-reason" name="reason" type="text" required maxlength="500" class="form-control">
</x-operator-modal>
@endif

{{-- ── Row 13 · Interval ────────────────────────────────────────── --}}
{{-- E1.4 / FB-19 — from the NEXT period. Switching mid-cycle re-priced a period
     they had already paid for; the running period is now left exactly as it is. --}}
@php($ivNext = $subscription?->pending_interval ?? $subscription?->interval)
<x-operator-modal id="m-interval" title="Switch billing period" :action="$act" preview="switch_interval" :account="$account"
    label="Switch" icon="bi-calendar3"
    intro="Takes effect at their next renewal. The period they are in now, and what they paid for it, stay as they are.">
    <label class="form-label" for="iv-interval">Bill from the next renewal</label>
    <select id="iv-interval" name="interval" class="form-select">
        <option value="monthly" @selected($ivNext === 'monthly')>Monthly</option>
        <option value="yearly" @selected($ivNext === 'yearly')>Yearly</option>
    </select>
</x-operator-modal>

{{-- ── Row 8 · Failed payment ───────────────────────────────────── --}}
@if ($subscription?->status === 'past_due')
<x-operator-modal id="m-waive" title="Waive what is owed" :action="$act" preview="waive" :account="$account"
    label="Waive it" dismiss="Never mind" icon="bi-hand-thumbs-up" tone="danger"
    intro="Write off this period. Recorded as a ₹0 entry, so it is visible rather than invisible.">
    <label class="form-label" for="wv-reason">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="wv-reason" name="reason" type="text" required maxlength="500" class="form-control">
</x-operator-modal>
@endif

{{-- ── E2 · Per charge: waive (given away, ₹0 entry) or void (never owed) ── --}}
@foreach (($charges ?? collect())->where('status', 'pending') as $open)
<x-operator-modal :id="'m-waive-' . $open->id" title="Waive {{ $open->number }}"
    :action="route('superadmin.accounts.charge.waive', [$account, $open->id])"
    preview="waive_charge" :account="$account"
    label="Waive it" dismiss="Never mind" icon="bi-hand-thumbs-up" tone="danger"
    intro="Give ₹ {{ number_format($open->amount, 2) }} ({{ $open->label() }}) away. Recorded as a ₹0 entry with your reason, and it still buys what it was for.">
    <input type="hidden" name="charge" value="{{ $open->id }}">
    <label class="form-label" for="wvc-reason-{{ $open->id }}">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="wvc-reason-{{ $open->id }}" name="reason" type="text" required maxlength="500" class="form-control">
</x-operator-modal>

<x-operator-modal :id="'m-void-' . $open->id" title="Void {{ $open->number }}"
    :action="route('superadmin.accounts.charge.void', [$account, $open->id])"
    preview="void_charge" :account="$account"
    label="Void it" dismiss="Never mind" icon="bi-x-circle" tone="danger"
    intro="This bill was raised in error: it is no longer owed. No entry is made on the ledger; the bill stays listed, struck through, with your reason.">
    <input type="hidden" name="charge" value="{{ $open->id }}">
    <label class="form-label" for="vdc-reason-{{ $open->id }}">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="vdc-reason-{{ $open->id }}" name="reason" type="text" required maxlength="500" class="form-control">
</x-operator-modal>
@endforeach

{{-- ── E3 · Money received online, on no bill: apply (previewed) or record a refund ── --}}
@foreach (($unapplied ?? collect()) as $u)
<x-operator-modal :id="'m-apply-' . $u->id" title="Apply ₹ {{ number_format($u->amount, 2) }} to a bill"
    :action="route('superadmin.accounts.unapplied.apply', [$account, $u->id])"
    preview="apply_unapplied" :account="$account"
    label="Apply" icon="bi-box-arrow-in-down"
    intro="It settles the bill exactly as if it had arrived for it. Only a bill of the same amount can take it.">
    <input type="hidden" name="unapplied" value="{{ $u->id }}">
    <label class="form-label" for="ap-bill-{{ $u->id }}">Bill</label>
    <select id="ap-bill-{{ $u->id }}" name="charge" class="form-select" required>
        <option value="">Choose…</option>
        @foreach (($charges ?? collect())->where('status', 'pending') as $open)
            <option value="{{ $open->id }}" @disabled(abs((float) $open->amount - (float) $u->amount) > 0.005)>
                {{ $open->number }} · {{ $open->label() }} — ₹ {{ number_format($open->amount, 2) }}{{ abs((float) $open->amount - (float) $u->amount) > 0.005 ? ' (different amount)' : '' }}
            </option>
        @endforeach
        {{-- No open bill can take it (an unknown order, legacy autopay): their next renewal can, if the price matches. --}}
        @if ($subscription)
            @php($renewal = (float) ($renewPrices[$payInterval] ?? 0))
            <option value="renewal" @disabled(abs($renewal - (float) $u->amount) > 0.005)>
                Their next renewal ({{ $payInterval }}) — ₹ {{ number_format($renewal, 2) }}{{ abs($renewal - (float) $u->amount) > 0.005 ? ' (different amount)' : '' }}
            </option>
        @endif
    </select>
    <label class="form-label mt-3" for="ap-note-{{ $u->id }}">Why this bill <span style="color:var(--tone-red);">*</span></label>
    <input id="ap-note-{{ $u->id }}" name="note" type="text" required maxlength="500" class="form-control"
           placeholder="e.g. paid after the bill was replaced">
</x-operator-modal>

<x-operator-modal :id="'m-refunded-' . $u->id" title="Record a refund"
    :action="route('superadmin.unapplied.refunded', $u->id)"
    label="Record refund" dismiss="Never mind" icon="bi-arrow-return-left" tone="danger"
    intro="Refund ₹ {{ number_format($u->amount, 2) }} in the Razorpay dashboard first, then record it here. Nothing changes for their access.">
    <label class="form-label" for="rfc-note-{{ $u->id }}">Refund reference <span style="color:var(--tone-red);">*</span></label>
    <input id="rfc-note-{{ $u->id }}" name="note" type="text" required maxlength="500" class="form-control"
           placeholder="e.g. rfnd_ABC123 — paid twice">
</x-operator-modal>
@endforeach

{{-- ── Row 10 · Suspend / reactivate ────────────────────────────── --}}
@if ($subscription?->override_kind === 'suspension')
<x-operator-modal id="m-reactivate" title="Reactivate" :action="$act" preview="reactivate" :account="$account"
    label="Reactivate" icon="bi-play-circle"
    intro="Restores access. Their paid-through date was preserved while suspended.">
    <label class="form-label" for="ra-reason">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="ra-reason" name="reason" type="text" required maxlength="500" class="form-control">
</x-operator-modal>
@else
<x-operator-modal id="m-suspend" title="Suspend access" :action="$act" preview="suspend" :account="$account"
    label="Suspend access" dismiss="Never mind" icon="bi-pause-circle" tone="danger"
    intro="Cuts access immediately. Their paid-through date is PRESERVED, so reactivating loses nothing.">
    <label class="form-label" for="sp-reason">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="sp-reason" name="reason" type="text" required maxlength="500" class="form-control"
           placeholder="e.g. non-payment after three reminders">
</x-operator-modal>
@endif

{{-- ── Row 12 · Cancel ──────────────────────────────────────────── --}}
<x-operator-modal id="m-cancel" title="Cancel this customer" :action="$act" preview="cancel" :account="$account"
    label="Cancel this customer" dismiss="Never mind" icon="bi-x-octagon" tone="danger"
    intro="Ends the relationship. There are no refunds, so by default they keep what they have paid for.">
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="at_period_end" value="1" id="cn-atend" checked>
        <label class="form-check-label" for="cn-atend">
            Let them keep access until {{ $liveEnd }} <span class="text-faint">(recommended)</span>
        </label>
    </div>
    <label class="form-label" for="cn-reason">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="cn-reason" name="reason" type="text" required maxlength="500" class="form-control">
</x-operator-modal>

{{-- ── Row 9 · Force expire ─────────────────────────────────────── --}}
<x-operator-modal id="m-expire" title="End access now" :action="$act" preview="force_expire" :account="$account"
    label="End access now" dismiss="Never mind" icon="bi-exclamation-octagon" tone="danger"
    intro="Immediately ends access, ignoring any remaining paid period. Rarely the right lever — prefer Suspend.">
    <label class="form-label" for="fe-reason">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="fe-reason" name="reason" type="text" required maxlength="500" class="form-control">
</x-operator-modal>

{{-- ── Notes ────────────────────────────────────────────────────── --}}
<x-operator-modal id="m-notes" title="Internal notes"
    :action="route('superadmin.accounts.notes', $account)" method="PATCH"
    label="Save notes" icon="bi-journal-text"
    intro="Private to you — never shown to the customer.">
    <textarea name="internal_notes" rows="6" class="form-control"
              placeholder="e.g. Called 3 Jul — wants annual billing from next renewal.">{{ $account->internal_notes }}</textarea>
</x-operator-modal>

{{-- ── Per-store levers ─────────────────────────────────────────── --}}
@foreach ($account->stores as $store)
    <x-operator-modal :id="'m-store-' . $store->id" title="{{ $store->store_status === 'suspended' ? 'Reactivate' : 'Suspend' }} {{ $store->store_name }}"
        :action="route('superadmin.accounts.store.status', [$account, $store])" method="PATCH"
        :label="$store->store_status === 'suspended' ? 'Reactivate store' : 'Suspend store'"
        icon="bi-shop" :tone="$store->store_status === 'suspended' ? 'primary' : 'danger'"
        intro="Affects this branch only. The customer's other stores and their billing are untouched.">
        <input type="hidden" name="store_status" value="{{ $store->store_status === 'suspended' ? 'active' : 'suspended' }}">
        <label class="form-label" for="ss-{{ $store->id }}">Why <span style="color:var(--tone-red);">*</span></label>
        <input id="ss-{{ $store->id }}" name="reason" type="text" required maxlength="500" class="form-control">
    </x-operator-modal>

    <x-operator-modal :id="'m-bill-' . $store->id" title="{{ $store->is_billable ? 'Exclude from billing' : 'Include in billing' }}"
        :action="route('superadmin.accounts.store.billable', [$account, $store])" method="PATCH"
        :label="$store->is_billable ? 'Exclude' : 'Include'" icon="bi-receipt-cutoff"
        :tone="$store->is_billable ? 'danger' : 'primary'"
        intro="Whether {{ $store->store_name }} counts toward what this customer is charged.">
        <input type="hidden" name="is_billable" value="{{ $store->is_billable ? 0 : 1 }}">
        <label class="form-label" for="sb-{{ $store->id }}">Why <span style="color:var(--tone-red);">*</span></label>
        <input id="sb-{{ $store->id }}" name="reason" type="text" required maxlength="500" class="form-control">
    </x-operator-modal>
@endforeach

{{-- ── Row 11 · Reverse a payment ───────────────────────────────── --}}
@foreach ($ledger->where('reversed_at', null) as $row)
    <x-operator-modal :id="'m-rev-' . $row->id" title="Reverse this payment"
        :action="route('superadmin.accounts.payment.reverse', [$account, $row])"
        label="Reverse payment" dismiss="Never mind" icon="bi-arrow-counterclockwise" tone="danger"
        intro="₹{{ number_format($row->amount, 2) }} · {{ $row->methodLabel() }}{{ $row->receipt_no ? ' · ' . $row->receipt_no : '' }}. The entry stays on the ledger, struck through — it never disappears.">
        <label class="form-label" for="rv-{{ $row->id }}">Why <span style="color:var(--tone-red);">*</span></label>
        <input id="rv-{{ $row->id }}" name="reason" type="text" required maxlength="500"
               class="form-control" placeholder="e.g. entered twice by mistake">
        {{-- ISS-02 — when the money being reversed is what is currently paying
             for their access, say so with the actual date. "This does not
             change their access" is true but easy to skim past; naming the day
             they keep access until makes the consequence concrete, and points
             at the lever that would actually cut it. --}}
        {{-- A-5 (E2) — a payment for a branch's part period bought no time, so it
             never "covers their access"; say what it did pay for instead. --}}
        @php($paidBill = $row->charge)
        @if ($paidBill?->kind === 'branch_join')
            <p class="text-muted-foreground text-xs mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                This paid <strong>{{ $paidBill->number }}</strong> ({{ $paidBill->label() }}). Reversing it makes that bill
                owed again. Their access does not change.
            </p>
        @elseif ($subscription
            && $row->period_end
            && $subscription->current_period_end
            && $row->period_end->gte(now()->startOfDay()))
            <div class="mt-3 p-3 rounded-3" style="background:var(--tone-amber-bg);">
                <p class="text-sm mb-0" style="color:var(--tone-amber);">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    This payment is what currently covers their access, through
                    <strong>{{ $row->period_end->format('d M Y') }}</strong>.
                    Reversing it takes the money off the books but
                    <strong>leaves that access in place</strong> — use
                    <strong>Suspend</strong> or <strong>End access now</strong> if that is what you mean.
                    @if ($paidBill)
                        What it paid for, <strong>{{ $paidBill->number }}</strong>, becomes owed again.
                    @endif
                </p>
            </div>
        @else
            <p class="text-muted-foreground text-xs mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                This does not change their access. Use <strong>Suspend</strong> or <strong>Cancel</strong> for that.
            </p>
        @endif
    </x-operator-modal>
@endforeach

{{-- ── Row 15 · Supervised mode, for this customer ──────────────── --}}
<x-operator-modal id="m-supervise"
    title="{{ $account->supervised ? 'Let them pay online again' : 'Bill this customer by hand' }}"
    :action="route('superadmin.accounts.supervised', $account)" method="PATCH"
    label="{{ $account->supervised ? 'Open self-serve' : 'Close self-serve' }}"
    dismiss="Never mind"
    tone="{{ $account->supervised ? 'primary' : 'danger' }}" icon="bi-person-check"
    intro="{{ $account->supervised
        ? 'They will be able to subscribe and change their plan online again.'
        : 'They will be told to contact you instead of paying online. Their access and data are untouched.' }}">
    <input type="hidden" name="supervised" value="{{ $account->supervised ? 0 : 1 }}">
    <label class="form-label" for="sup-reason">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="sup-reason" name="reason" type="text" required maxlength="500" class="form-control"
           placeholder="e.g. they pay by bank transfer each year">
</x-operator-modal>

{{-- ══════════════════════════════════════════════════════════════
     P5 · Access & operations
     Rows 14 (credentials) and 16 (closure), plus read-only view-as.
     ══════════════════════════════════════════════════════════════ --}}

{{-- ── Row 16 · Closure, in two steps weeks apart ───────────────── --}}
@foreach ($account->stores as $store)
    @if ($store->isClosed())
        <x-operator-modal :id="'m-reopen-' . $store->id" title="Reopen {{ $store->store_name }}"
            :action="route('superadmin.accounts.store.reopen', [$account, $store])"
            label="Reopen store" dismiss="Never mind" icon="bi-arrow-counterclockwise"
            intro="Everything is exactly where they left it — customers, orders, inventory and staff logins all come straight back.">
            <label class="form-label" for="ro-{{ $store->id }}">Why <span style="color:var(--tone-red);">*</span></label>
            <input id="ro-{{ $store->id }}" name="reason" type="text" required maxlength="500"
                   class="form-control" placeholder="e.g. changed their mind, paying again">
        </x-operator-modal>

        @if ($store->isPurgeable())
            <x-operator-modal :id="'m-purge-' . $store->id" title="Delete {{ $store->store_name }} permanently"
                :action="route('superadmin.accounts.store.purge', [$account, $store])" method="DELETE"
                label="Delete everything" dismiss="Keep it" icon="bi-trash3" tone="danger"
                :confirm="$store->store_name"
                confirmLabel="Type the store name exactly to confirm"
                intro="This destroys every customer record, prescription, order and login belonging to this store. It cannot be undone from the panel: the only copy is the most recent database backup, which the system checks exists before it will go ahead.">
                <div class="p-3 rounded-3 mb-1" style="background:var(--tone-red-bg);">
                    <p class="text-sm mb-0" style="color:var(--tone-red);">
                        <strong>{{ number_format($storeRowCounts[$store->id] ?? 0) }} rows</strong> and
                        <strong>{{ $store->users_count }} {{ Str::plural('login', $store->users_count) }}</strong>
                        will be destroyed.
                    </p>
                </div>
                <p class="text-xs mt-2 mb-0" style="color:var(--tone-green);">
                    <i class="bi bi-shield-check me-1"></i>
                    The customer's subscription and payment history are <strong>not</strong> touched.
                </p>
                <label class="form-label mt-2" for="pg-{{ $store->id }}">Why <span style="color:var(--tone-red);">*</span></label>
                <input id="pg-{{ $store->id }}" name="reason" type="text" required maxlength="500" class="form-control">
            </x-operator-modal>
        @endif
    @else
        <x-operator-modal :id="'m-close-' . $store->id" title="Close {{ $store->store_name }}"
            :action="route('superadmin.accounts.store.close', [$account, $store])"
            label="Close this store" dismiss="Never mind" icon="bi-archive" tone="danger"
            intro="Access stops immediately. Nothing is deleted — their data is kept for {{ config('saas.closure_retention_days', 30) }} days and can be restored in full at any point in that window.">
            <label class="form-label" for="cl-{{ $store->id }}">Why <span style="color:var(--tone-red);">*</span></label>
            <input id="cl-{{ $store->id }}" name="reason" type="text" required maxlength="500"
                   class="form-control" placeholder="e.g. shop sold, moving to another system">
            <p class="text-muted-foreground text-xs mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Their subscription is left alone. If this is their last store, cancel the
                customer separately — closing a branch must never stop the money for the others.
            </p>
        </x-operator-modal>
    @endif
@endforeach

{{-- ── Row 14 · Credentials, and read-only view-as ──────────────── --}}
@foreach ($account->stores as $store)
    @foreach ($store->users as $u)
        <x-operator-modal :id="'m-cred-' . $u->id" title="New password for {{ $u->name }}"
            :action="route('superadmin.accounts.credential', [$account, $u])"
            label="Issue new password" dismiss="Never mind" icon="bi-key" tone="danger"
            intro="Their current password stops working immediately, and any device still signed in with “remember me” is signed out. The new one is shown to you once.">
            <label class="form-label" for="cr-{{ $u->id }}">Why <span style="color:var(--tone-red);">*</span></label>
            <input id="cr-{{ $u->id }}" name="reason" type="text" required maxlength="500"
                   class="form-control" placeholder="e.g. locked out, reset email never arrived">
            <p class="text-muted-foreground text-xs mt-3 mb-0">
                <i class="bi bi-shield-lock me-1"></i>
                The password is never stored or logged — only that you issued one, and why.
            </p>
        </x-operator-modal>

        @if ($store->store_status === 'active')
            <x-operator-modal :id="'m-view-' . $u->id" title="View {{ $store->store_name }} as {{ $u->name }}"
                :action="route('superadmin.accounts.impersonate', [$account, $u])"
                label="Start viewing" dismiss="Never mind" icon="bi-eye"
                intro="You will see exactly what they see, and you will not be able to change anything. The session ends by itself after {{ config('saas.impersonation_minutes', 30) }} minutes.">
                <label class="form-label" for="im-{{ $u->id }}">Why <span style="color:var(--tone-red);">*</span></label>
                <input id="im-{{ $u->id }}" name="reason" type="text" required maxlength="500"
                       class="form-control" placeholder="e.g. support call — their order list looks empty">
                <p class="text-muted-foreground text-xs mt-3 mb-0">
                    <i class="bi bi-record-circle me-1"></i>
                    Starting and leaving are both recorded against your name, with the duration.
                </p>
            </x-operator-modal>
        @endif
    @endforeach
@endforeach


@once
@push('scripts')
<script nonce="{{ csp_nonce() }}">
/**
 * D-5 — Record a payment: what it pays for, and the amount that follows it.
 *
 * The amount follows the chosen bill (an open charge, or the next renewal at the
 * chosen period × months ahead) until the operator types their own; after that it
 * is theirs and is never overwritten. `bill` is the number the server will hold
 * the payment against, so the discount panel and the D-4 warning compare against
 * exactly what Settlement will.
 *
 * Opened from an "Owed" row (data-pay-for), it starts on that bill.
 * Motion contract: panels fade (x-transition), never pop.
 */
function paymentField(bills, prices, interval, forId) {
    const money = (n) => Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const start = { interval, forId };

    return {
        bills,
        prices,
        interval,
        forId,
        cycles: 1,
        touched: false,
        confirmed: false,
        amount: 0,
        init() {
            this.amount = this.bill;
            this.$watch('forId', () => this.follow());
            this.$watch('interval', () => { this.cycles = Math.min(Math.max(1, Number(this.cycles) || 1), this.maxCycles); this.follow(); });
            this.$watch('cycles', () => this.follow());
            this.$watch('amount', () => { if (!this.over) this.confirmed = false; });

            const modal = this.$el.closest('.modal');
            modal?.addEventListener('show.bs.modal', (e) => {
                const id = e.relatedTarget?.dataset?.payFor;
                if (id && this.bills.some((b) => b.id === id)) {
                    this.forId = id;
                    this.touched = false;
                    this.amount = this.bill;
                }
            });
            // A fresh start every time it opens: never yesterday's half-typed payment.
            modal?.addEventListener('hidden.bs.modal', () => {
                Object.assign(this, { interval: start.interval, forId: start.forId, cycles: 1, touched: false, confirmed: false });
                this.amount = this.bill;
            });
        },
        follow() { if (!this.touched) this.amount = this.bill; },
        get isRenewal() { return this.forId === 'renewal'; },
        get maxCycles() { return this.interval === 'yearly' ? 3 : 12; },
        get bill() {
            if (this.isRenewal) {
                const n = Math.min(Math.max(1, Number(this.cycles) || 1), this.maxCycles);
                return Math.round((Number(this.prices[this.interval]) || 0) * n * 100) / 100;
            }
            const b = this.bills.find((x) => x.id === this.forId);
            return b ? Number(b.amount) : 0;
        },
        get billLabel() { return money(this.bill); },
        get discounted() { return this.bill > 0 && Number(this.amount) > 0 && Number(this.amount) < this.bill; },
        get over() { return this.bill > 0 && Number(this.amount) > this.bill + 0.005; },
        get saved() { return money(this.bill - Number(this.amount)); },
        get excess() { return money(Number(this.amount) - this.bill); },
    };
}
</script>
@endpush
@endonce

{{-- ── E4-I · Billing contact (reminder emails, WhatsApp nudge) ─────── --}}
<x-operator-modal id="m-contact" title="Billing contact"
    :action="route('superadmin.accounts.contact', $account)" method="PATCH"
    label="Save" icon="bi-person-lines-fill"
    intro="Renewal reminders go to the owner's email and this one. The phone is what the WhatsApp nudge on Today opens.">
    <label class="form-label" for="ct-email">Billing email</label>
    <input id="ct-email" name="billing_email" type="email" maxlength="255" class="form-control"
           value="{{ old('billing_email', $account->billing_email) }}" placeholder="accounts@theirshop.in">
    <label class="form-label mt-3" for="ct-phone">Phone (WhatsApp)</label>
    <input id="ct-phone" name="billing_phone" type="tel" maxlength="30" class="form-control"
           value="{{ old('billing_phone', $account->billing_phone) }}" placeholder="98765 43210">
    <p class="text-muted-foreground text-xs mt-1 mb-0">Indian numbers need no country code.</p>
</x-operator-modal>

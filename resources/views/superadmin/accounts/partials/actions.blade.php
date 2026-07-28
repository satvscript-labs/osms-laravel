{{--
    P3 — every commercial action for one account, each in the shared overlay.
    Rendered once at the foot of the 360; triggers live in the header and tabs,
    so every matrix row is reachable in ≤3 clicks from the customer's screen.
--}}
@php
    $act = route('superadmin.accounts.action', $account);
    $liveEnd = $subscription?->current_period_end?->format('d M Y') ?? '—';
@endphp

{{-- ── Row 4 · Record a payment ─────────────────────────────────── --}}
<x-operator-modal id="m-payment" title="Record a payment"
    :action="route('superadmin.accounts.payment', $account)"
    label="Record payment" icon="bi-cash-coin"
    intro="A payment that arrived outside the gateway — cash at the counter, UPI, a bank transfer.">
    <div class="row g-3">
        <div class="col-6">
            <label class="form-label" for="pay-amount">Amount</label>
            <div class="input-group">
                <span class="input-group-text">₹</span>
                <input id="pay-amount" name="amount" type="number" step="0.01" min="0" required
                       value="{{ $price['effective'] ?? '' }}" class="form-control" inputmode="decimal">
            </div>
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
            <input id="pay-date" name="paid_at" type="date" class="form-control" value="{{ now()->toDateString() }}">
        </div>
        <div class="col-6">
            <label class="form-label" for="pay-ref">Reference <span class="text-faint">optional</span></label>
            <input id="pay-ref" name="reference" type="text" class="form-control" placeholder="UPI ref, cheque no…">
        </div>
        <div class="col-12">
            <label class="form-label" for="pay-note">Note <span class="text-faint">optional</span></label>
            <input id="pay-note" name="reason" type="text" class="form-control" maxlength="500">
        </div>
    </div>
    <p class="text-muted-foreground text-xs mt-3 mb-0">
        <i class="bi bi-info-circle me-1"></i>
        This records the money. To also move their renewal date, use <strong>Renew now</strong>.
    </p>
</x-operator-modal>

{{-- ── Row 5 · Renew now ────────────────────────────────────────── --}}
<x-operator-modal id="m-renew" title="Renew now" :action="$act" preview="renew" :account="$account"
    label="Take payment & renew" icon="bi-arrow-repeat"
    intro="Records the payment AND moves the renewal date. Manual wins for this cycle.">
    <div class="row g-3">
        <div class="col-6">
            <label class="form-label" for="rn-amount">Amount</label>
            <div class="input-group">
                <span class="input-group-text">₹</span>
                <input id="rn-amount" name="amount" type="number" step="0.01" min="0"
                       value="{{ $price['effective'] ?? '' }}" class="form-control" inputmode="decimal">
            </div>
        </div>
        <div class="col-6">
            <label class="form-label" for="rn-interval">Period</label>
            <select id="rn-interval" name="interval" class="form-select">
                <option value="monthly" @selected($subscription?->interval === 'monthly')>1 month</option>
                <option value="yearly" @selected($subscription?->interval === 'yearly')>1 year</option>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label" for="rn-method">Method</label>
            <select id="rn-method" name="method" class="form-select">
                <option value="cash">Cash</option>
                <option value="upi">UPI</option>
                <option value="bank_transfer">Bank transfer</option>
                <option value="cheque">Cheque</option>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label" for="rn-ref">Reference <span class="text-faint">optional</span></label>
            <input id="rn-ref" name="reference" type="text" class="form-control">
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
    label="Clear negotiated price" icon="bi-tag" tone="danger"
    intro="Removes the bespoke price. They will pay the plan's list price from the next renewal.">
    <label class="form-label" for="cpz-reason">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="cpz-reason" name="reason" type="text" required maxlength="500" class="form-control">
</x-operator-modal>
@endif

{{-- ── Row 13 · Interval ────────────────────────────────────────── --}}
<x-operator-modal id="m-interval" title="Switch billing period" :action="$act" preview="switch_interval" :account="$account"
    label="Switch" icon="bi-calendar3">
    <label class="form-label" for="iv-interval">Bill</label>
    <select id="iv-interval" name="interval" class="form-select">
        <option value="monthly" @selected($subscription?->interval === 'monthly')>Monthly</option>
        <option value="yearly" @selected($subscription?->interval === 'yearly')>Yearly</option>
    </select>
</x-operator-modal>

{{-- ── Row 8 · Failed payment ───────────────────────────────────── --}}
@if ($subscription?->status === 'past_due')
<x-operator-modal id="m-markpaid" title="Mark as paid" :action="$act" preview="mark_paid" :account="$account"
    label="Mark paid" icon="bi-check2-circle"
    intro="They have paid but the gateway did not tell us. Records the money and stops the chasing.">
    <div class="row g-3">
        <div class="col-6">
            <label class="form-label" for="mp-amount">Amount</label>
            <div class="input-group">
                <span class="input-group-text">₹</span>
                <input id="mp-amount" name="amount" type="number" step="0.01" min="0"
                       value="{{ $price['effective'] ?? '' }}" class="form-control">
            </div>
        </div>
        <div class="col-6">
            <label class="form-label" for="mp-method">Method</label>
            <select id="mp-method" name="method" class="form-select">
                <option value="cash">Cash</option>
                <option value="upi">UPI</option>
                <option value="bank_transfer">Bank transfer</option>
                <option value="cheque">Cheque</option>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label" for="mp-reason">Why <span style="color:var(--tone-red);">*</span></label>
            <input id="mp-reason" name="reason" type="text" required maxlength="500" class="form-control">
        </div>
    </div>
</x-operator-modal>

<x-operator-modal id="m-waive" title="Waive what is owed" :action="$act" preview="waive" :account="$account"
    label="Waive" icon="bi-hand-thumbs-up" tone="danger"
    intro="Write off this period. Recorded as a ₹0 entry, so it is visible rather than invisible.">
    <label class="form-label" for="wv-reason">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="wv-reason" name="reason" type="text" required maxlength="500" class="form-control">
</x-operator-modal>
@endif

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
    label="Suspend" icon="bi-pause-circle" tone="danger"
    intro="Cuts access immediately. Their paid-through date is PRESERVED, so reactivating loses nothing.">
    <label class="form-label" for="sp-reason">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="sp-reason" name="reason" type="text" required maxlength="500" class="form-control"
           placeholder="e.g. non-payment after three reminders">
</x-operator-modal>
@endif

{{-- ── Row 12 · Cancel ──────────────────────────────────────────── --}}
<x-operator-modal id="m-cancel" title="Cancel this customer" :action="$act" preview="cancel" :account="$account"
    label="Cancel" icon="bi-x-octagon" tone="danger"
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
    label="End access" icon="bi-exclamation-octagon" tone="danger"
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
        label="Reverse" icon="bi-arrow-counterclockwise" tone="danger"
        intro="₹{{ number_format($row->amount, 2) }} · {{ $row->methodLabel() }}{{ $row->receipt_no ? ' · ' . $row->receipt_no : '' }}. The entry stays on the ledger, struck through — it never disappears.">
        <label class="form-label" for="rv-{{ $row->id }}">Why <span style="color:var(--tone-red);">*</span></label>
        <input id="rv-{{ $row->id }}" name="reason" type="text" required maxlength="500"
               class="form-control" placeholder="e.g. entered twice by mistake">
        <p class="text-muted-foreground text-xs mt-3 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            This does not change their access. Use <strong>Suspend</strong> or <strong>Cancel</strong> for that.
        </p>
    </x-operator-modal>
@endforeach

@extends('layouts.app')
@section('title', $account ? 'Add a store' : 'New customer')

@section('content')
{{--
    P5 / REQ-2, matrix rows 1–2 — the operator's provisioning door.

    Two modes, one form. With `?account=` it adds a branch to somebody who
    already pays you (no second clock, no second bill until renewal — decision
    A3); without it, it creates the customer, their store, their login and their
    trial in one submission.

    The form is ordered the way the conversation goes: who is paying, what shop,
    what terms. Not the way the tables are shaped.
--}}
<div class="p-4 p-md-5" style="max-width:52rem;">

    <a href="{{ $account ? route('superadmin.accounts.show', $account) : route('superadmin.accounts.index') }}"
       class="text-decoration-none text-muted-foreground d-inline-flex align-items-center gap-1 mb-3 text-sm">
        <i class="bi bi-arrow-left"></i> {{ $account ? $account->displayName() : 'All customers' }}
    </a>

    <div class="mb-4">
        <p class="section-label mb-1">{{ $account ? 'Add a store' : 'New customer' }}</p>
        <h1 class="h3 fw-semibold font-display mb-1">
            {{ $account ? "Another store for {$account->displayName()}" : 'Set up a customer' }}
        </h1>
        <p class="text-muted-foreground mb-0 text-md">
            @if ($account)
                This branch joins their existing subscription — it does not start a second clock,
                and it starts counting toward the bill at their next renewal.
            @else
                Creates the customer, their first store and the owner's login. You will be shown
                the password once, to give them.
            @endif
        </p>
    </div>

    <form method="POST" action="{{ route('superadmin.accounts.store') }}" class="stagger">
        @csrf
        @if ($account) <input type="hidden" name="account_id" value="{{ $account->id }}"> @endif

        {{-- ---- Who ---- --}}
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body p-4">
                <p class="section-label mb-1">The owner</p>
                <p class="text-muted-foreground text-sm mb-3">
                    The person who signs in and runs the shop. This is who the password is for.
                </p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="owner_name">Name <span style="color:var(--tone-red);">*</span></label>
                        <input id="owner_name" name="owner_name" type="text" required maxlength="120"
                               class="form-control @error('owner_name') is-invalid @enderror"
                               value="{{ old('owner_name') }}" placeholder="e.g. Rushi Patel">
                        @error('owner_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="owner_email">Email <span style="color:var(--tone-red);">*</span></label>
                        <input id="owner_email" name="owner_email" type="email" required maxlength="190"
                               class="form-control @error('owner_email') is-invalid @enderror"
                               value="{{ old('owner_email') }}" placeholder="name@example.com">
                        @error('owner_email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text text-xs">They sign in with this. It cannot already be in use.</div>
                    </div>
                </div>

                @unless ($account)
                    {{-- 06 §6 — the customer is the PERSON, the store is the shop.
                         Left blank it takes the owner's name, which is right far
                         more often than not. --}}
                    <div class="row g-3 mt-0">
                        <div class="col-md-6">
                            <label class="form-label" for="customer_name">Bill under the name</label>
                            <input id="customer_name" name="customer_name" type="text" maxlength="120"
                                   class="form-control" value="{{ old('customer_name') }}"
                                   placeholder="Defaults to the owner's name">
                            <div class="form-text text-xs">
                                Who you are billing, if that differs from the person above.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="billing_phone">Phone</label>
                            <input id="billing_phone" name="billing_phone" type="text" maxlength="30"
                                   class="form-control" value="{{ old('billing_phone') }}">
                        </div>
                    </div>
                @endunless
            </div>
        </div>

        {{-- ---- What ---- --}}
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body p-4">
                <p class="section-label mb-3">The store</p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="store_name">Store name <span style="color:var(--tone-red);">*</span></label>
                        <input id="store_name" name="store_name" type="text" required maxlength="150"
                               class="form-control @error('store_name') is-invalid @enderror"
                               value="{{ old('store_name') }}" placeholder="e.g. Sahaj Optical">
                        @error('store_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="tax_id">GSTIN</label>
                        <input id="tax_id" name="tax_id" type="text" maxlength="40"
                               class="form-control" value="{{ old('tax_id') }}">
                        <div class="form-text text-xs">Theirs, for their own invoices. Optional.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="address">Address</label>
                        <textarea id="address" name="address" rows="2" maxlength="500"
                                  class="form-control">{{ old('address') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        {{-- ---- Terms ---- --}}
        @unless ($account)
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Terms</p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="plan_code">Plan</label>
                            <select id="plan_code" name="plan_code" class="form-select">
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->code }}" @selected(old('plan_code', 'basic') === $plan->code)>
                                        {{ $plan->name }} — ₹ {{ number_format($plan->monthly_price, 0) }}/mo
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text text-xs">
                                A hand-agreed price is set afterwards, on the customer.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="trial_days">Free trial (days)</label>
                            <input id="trial_days" name="trial_days" type="number" min="0" max="365"
                                   class="form-control @error('trial_days') is-invalid @enderror"
                                   value="{{ old('trial_days', $defaultTrialDays) }}">
                            @error('trial_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text text-xs">
                                0 is valid — for a customer who has already paid.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endunless

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-shop-window me-1"></i>
                {{ $account ? 'Add this store' : 'Create customer' }}
            </button>
            <a href="{{ $account ? route('superadmin.accounts.show', $account) : route('superadmin.accounts.index') }}"
               class="btn btn-light">Cancel</a>
        </div>
    </form>
</div>
@endsection

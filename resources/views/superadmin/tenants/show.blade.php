@extends('layouts.app')
@section('title', $tenant->store_name)

@php
    $s = $subscription;
    $access = $s?->accessState() ?? 'locked';
    $accessTone = ['active' => 'success', 'grace' => 'warning', 'locked' => 'secondary'][$access] ?? 'secondary';
@endphp

@section('content')
<div class="p-4 p-md-5">
    <a href="{{ route('superadmin.tenants.index') }}" class="text-decoration-none text-muted-foreground d-inline-flex align-items-center gap-1 mb-3" style="font-size:var(--text-sm);">
        <i class="bi bi-arrow-left"></i> All stores
    </a>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="d-inline-flex align-items-center justify-content-center rounded-4 bg-primary text-white fw-semibold"
                  style="width:3rem;height:3rem;font-size:1.25rem;">{{ strtoupper(substr($tenant->store_name, 0, 1)) }}</span>
            <div>
                <h1 class="h4 fw-semibold font-display mb-1">{{ $tenant->store_name }}</h1>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge text-bg-{{ in_array($s?->status, ['active','trialing']) ? 'success' : ($s?->status === 'past_due' ? 'warning' : 'secondary') }}">{{ $s?->status ?? 'none' }}</span>
                    <span class="badge text-bg-{{ $accessTone }}">access: {{ $access }}</span>
                    @if ($s?->manual)<span class="badge text-bg-light border">manually managed</span>@endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        {{-- Main column --}}
        <div class="col-lg-8">
            {{-- Store info --}}
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Store details</p>
                    <div class="row g-3" style="font-size:var(--text-md);">
                        <div class="col-md-6">
                            <p class="text-muted-foreground mb-0" style="font-size:var(--text-xs);">Owner</p>
                            <p class="fw-medium mb-0">{{ $owner?->email ?? '—' }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted-foreground mb-0" style="font-size:var(--text-xs);">GST / Tax ID</p>
                            <p class="fw-medium mb-0">{{ $tenant->tax_id ?: '—' }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted-foreground mb-0" style="font-size:var(--text-xs);">Address</p>
                            <p class="fw-medium mb-0">{{ $tenant->address ?: '—' }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted-foreground mb-0" style="font-size:var(--text-xs);">Joined</p>
                            <p class="fw-medium mb-0">{{ $tenant->created_at?->format('d M Y') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Subscription management --}}
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Subscription</p>

                    <div class="row g-3 mb-4" style="font-size:var(--text-md);">
                        <div class="col-6 col-md-3">
                            <p class="text-muted-foreground mb-0" style="font-size:var(--text-xs);">Tier</p>
                            <p class="fw-medium mb-0 text-capitalize">{{ $s?->tier ?? '—' }}</p>
                        </div>
                        <div class="col-6 col-md-3">
                            <p class="text-muted-foreground mb-0" style="font-size:var(--text-xs);">Interval</p>
                            <p class="fw-medium mb-0 text-capitalize">{{ $s?->interval ?? '—' }}</p>
                        </div>
                        <div class="col-6 col-md-3">
                            <p class="text-muted-foreground mb-0" style="font-size:var(--text-xs);">Period ends</p>
                            <p class="fw-medium mb-0">{{ $s?->current_period_end?->format('d M Y') ?? '—' }}</p>
                        </div>
                        <div class="col-6 col-md-3">
                            <p class="text-muted-foreground mb-0" style="font-size:var(--text-xs);">Razorpay</p>
                            <p class="fw-medium mb-0">{{ $s?->razorpay_subscription_id ? 'linked' : '—' }}</p>
                        </div>
                    </div>

                    {{-- Quick actions --}}
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="border rounded-4 p-3 h-100">
                                <p class="fw-semibold mb-2" style="font-size:var(--text-sm);"><i class="bi bi-hourglass-split me-1 text-warning"></i> Extend trial</p>
                                <form method="POST" action="{{ route('superadmin.subscription.extend-trial', $tenant) }}" class="d-flex gap-2">
                                    @csrf
                                    <input type="number" name="days" min="1" max="365" value="14" class="form-control" required style="max-width:100px;">
                                    <input type="text" name="reason" class="form-control" required maxlength="500" placeholder="Why?">
                                    <button class="btn btn-light flex-grow-1">Add days</button>
                                </form>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded-4 p-3 h-100">
                                <p class="fw-semibold mb-2" style="font-size:var(--text-sm);"><i class="bi bi-gift me-1 text-primary"></i> Grant free access</p>
                                <form method="POST" action="{{ route('superadmin.subscription.activate', $tenant) }}" class="d-flex gap-2">
                                    @csrf
                                    <input type="number" name="months" min="1" max="60" value="1" class="form-control" required style="max-width:80px;">
                                    <select name="interval" class="form-select" style="max-width:110px;">
                                        <option value="monthly">monthly</option>
                                        <option value="yearly">yearly</option>
                                    </select>
                                    <input type="text" name="reason" class="form-control" required maxlength="500" placeholder="Why?">
                                    <button class="btn btn-primary flex-grow-1">Grant</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    {{-- Advanced edit --}}
                    <details class="mt-4">
                        <summary class="fw-semibold" style="font-size:var(--text-sm);cursor:pointer;">Advanced: edit raw subscription</summary>
                        <form method="POST" action="{{ route('superadmin.subscription.update', $tenant) }}" class="row g-3 mt-1">
                            @csrf @method('PATCH')
                            <div class="col-md-4">
                                <label class="form-label small fw-medium">Status</label>
                                <select name="status" class="form-select">
                                    @foreach (['trialing','active','past_due','canceled'] as $opt)
                                        <option value="{{ $opt }}" @selected($s?->status === $opt)>{{ $opt }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-medium">Tier</label>
                                <select name="tier" class="form-select">
                                    @foreach (['basic','pro','enterprise'] as $opt)
                                        <option value="{{ $opt }}" @selected($s?->tier === $opt)>{{ $opt }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-medium">Interval</label>
                                <select name="interval" class="form-select">
                                    <option value="">—</option>
                                    <option value="monthly" @selected($s?->interval === 'monthly')>monthly</option>
                                    <option value="yearly" @selected($s?->interval === 'yearly')>yearly</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-medium">Period ends</label>
                                <input type="date" name="current_period_end" value="{{ $s?->current_period_end?->format('Y-m-d') }}" class="form-control">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="cancel_at_period_end" value="1" class="form-check-input" id="cape" @checked($s?->cancel_at_period_end)>
                                    <label class="form-check-label small" for="cape">Cancel at period end</label>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small fw-medium">Why <span style="color:var(--tone-red);">*</span></label>
                                <input type="text" name="reason" class="form-control" required maxlength="500">
                            </div>
                            <div class="col-12">
                                <button class="btn btn-secondary">Save changes</button>
                            </div>
                        </form>
                    </details>

                    <hr class="my-4">

                    <form method="POST" action="{{ route('superadmin.subscription.cancel', $tenant) }}">
                        @csrf
                        <input type="text" name="reason" class="form-control mb-2" required maxlength="500"
                               placeholder="Why are you cancelling?" style="max-width:24rem;">
                        <button type="button" class="btn btn-light text-danger"
                                data-confirm="This cancels the store's access immediately. If a live Razorpay subscription exists, it will also be canceled at cycle end."
                                data-confirm-title="Cancel this subscription?"
                                data-confirm-label="Cancel subscription"
                                data-confirm-tone="danger">
                            <i class="bi bi-x-octagon me-1"></i> Cancel subscription now
                        </button>
                    </form>
                </div>
            </div>

            {{-- Invoices --}}
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Payment history</p>
                    @forelse ($tenant->subscriptionInvoices as $inv)
                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom" style="font-size:var(--text-sm);">
                            <div>
                                <span class="fw-medium">₹ {{ number_format($inv->amount, 2) }}</span>
                                <span class="badge text-bg-success ms-1">{{ $inv->status }}</span>
                            </div>
                            <span class="text-muted-foreground">{{ ($inv->paid_at ?? $inv->created_at)?->format('d M Y') }}</span>
                        </div>
                    @empty
                        <p class="text-muted-foreground text-center py-3 mb-0" style="font-size:var(--text-sm);">No payments yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Right column --}}
        <div class="col-lg-4">
            {{-- Internal notes --}}
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <p class="section-label mb-2">Internal notes</p>
                    <p class="text-muted-foreground mb-3" style="font-size:var(--text-sm);">Private — never shown to the store.</p>
                    <form method="POST" action="{{ route('superadmin.tenants.notes', $tenant) }}">
                        @csrf @method('PATCH')
                        <textarea name="internal_notes" rows="5" class="form-control mb-2" placeholder="e.g. Called on 3 Jul, wants annual plan…">{{ $tenant->internal_notes }}</textarea>
                        <button class="btn btn-light w-100">Save notes</button>
                    </form>
                </div>
            </div>

            {{-- Team --}}
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Team ({{ $tenant->users->count() }})</p>
                    @foreach ($tenant->users as $u)
                        <div class="d-flex align-items-center gap-2 py-1" style="font-size:var(--text-sm);">
                            <div class="flex-grow-1 min-w-0">
                                <p class="mb-0 fw-medium text-truncate">{{ $u->name }}</p>
                                <p class="mb-0 text-muted-foreground text-truncate" style="font-size:var(--text-xs);">{{ $u->email }}</p>
                            </div>
                            <span class="badge {{ $u->role === 'store_admin' ? 'text-bg-primary' : 'text-bg-secondary' }}">{{ $u->role === 'store_admin' ? 'admin' : 'staff' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Audit trail --}}
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Activity on this store</p>
                    @forelse ($tenant->auditLogs as $log)
                        <div class="py-2 border-bottom" style="font-size:var(--text-sm);">
                            <p class="mb-0 fw-medium">{{ $log->description }}</p>
                            <p class="mb-0 text-muted-foreground" style="font-size:var(--text-xs);">
                                {{ $log->admin_email ?? 'system' }} · {{ $log->created_at?->diffForHumans() }}
                            </p>
                        </div>
                    @empty
                        <p class="text-muted-foreground text-center py-3 mb-0" style="font-size:var(--text-sm);">No admin actions yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title', 'Billing')

@section('content')
{{--
    P2 / REQ-5 — the ONE ledger, across every account.
    Cash, UPI, bank transfer, Razorpay and ₹0 comps appear side by side here
    because they are the same record shape. Revenue is one query, never a
    union of two subsystems.
--}}
<div class="p-4 p-md-5">

    <div class="mb-4">
        <p class="section-label mb-1">Platform</p>
        <h1 class="h3 fw-semibold font-display mb-1">Billing</h1>
        <p class="text-muted-foreground mb-0 text-md">
            Every payment, comp and reversal — one ledger, every channel.
        </p>
    </div>

    <div class="row g-3 mb-4 stagger">
        <div class="col-6 col-lg-3">
            <x-metric-card label="Collected this month" value="₹ {{ number_format($totals['this_month'], 0) }}" icon="bi-cash-coin" tone="primary" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Collected all time" value="₹ {{ number_format($totals['collected'], 0) }}" icon="bi-safe" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Complimentary grants" value="{{ $totals['comped'] }}" icon="bi-gift" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Reversed" value="{{ $totals['reversed'] }}" icon="bi-arrow-counterclockwise"
                           tone="{{ $totals['reversed'] > 0 ? 'amber' : 'default' }}" />
        </div>
    </div>

    <form method="GET" class="d-flex flex-column flex-lg-row gap-3 align-items-lg-center justify-content-between mb-3">
        <div class="input-group flex-nowrap" style="max-width:30rem;">
            <span class="input-group-text bg-white border-end-0">
                <i class="bi bi-search text-muted-foreground"></i>
            </span>
            <input type="search" name="q" value="{{ $search }}" class="form-control border-start-0"
                   placeholder="Receipt no, reference, or customer…" autocomplete="off" aria-label="Search payments">
            @if ($method !== 'all')<input type="hidden" name="method" value="{{ $method }}">@endif
            <button class="btn btn-light border-start-0" type="submit" aria-label="Search"><i class="bi bi-arrow-right"></i></button>
        </div>

        <div class="chip-rail">
            @foreach (['all' => 'All', 'razorpay' => 'Razorpay', 'cash' => 'Cash', 'upi' => 'UPI', 'bank_transfer' => 'Bank', 'cheque' => 'Cheque', 'comp' => 'Comp'] as $key => $label)
                <a href="{{ route('superadmin.billing.index', array_filter(['method' => $key, 'q' => $search])) }}"
                   class="meta-chip text-decoration-none {{ $method === $key ? 'fw-semibold' : '' }}"
                   @if ($method === $key) style="background:var(--osms-primary-soft); color:var(--osms-primary);" @endif>
                    {{ $label }}
                    @if ($key !== 'all' && isset($methodCounts[$key]))
                        <span class="text-faint">{{ $methodCounts[$key] }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </form>

    <p class="text-muted-foreground text-sm mb-3">
        {{ $rows->total() }} {{ Str::plural('entry', $rows->total()) }}
    </p>

    @if ($rows->isNotEmpty())
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden stagger">
            @foreach ($rows as $row)
                <div class="person-row" style="cursor:default;">
                    <span class="person-avatar">
                        <i class="bi {{ $row->method === 'comp' ? 'bi-gift' : 'bi-cash' }}"></i>
                    </span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="fw-semibold {{ $row->isReversed() ? 'text-decoration-line-through text-faint' : '' }}">
                                ₹ {{ number_format($row->amount, 2) }}
                            </span>
                            <span class="meta-chip">{{ $row->methodLabel() }}</span>
                            @if ($row->isReversed())
                                <span class="osms-badge osms-badge-red"><span class="osms-badge-dot"></span>Reversed</span>
                            @endif
                        </div>
                        <div class="text-muted-foreground text-sm mt-1 d-flex align-items-center gap-2 flex-wrap">
                            @if ($row->account)
                                <a href="{{ route('superadmin.accounts.show', $row->account_id) }}"
                                   class="text-decoration-none">{{ $row->account->displayName() }}</a>
                            @endif
                            @if ($row->tenant)<span class="text-faint">· {{ $row->tenant->store_name }}</span>@endif
                            @if ($row->receipt_no)<span class="font-monospace text-xs">{{ $row->receipt_no }}</span>@endif
                            @if ($row->reference)<span class="text-faint">ref {{ $row->reference }}</span>@endif
                        </div>
                    </div>
                    <div class="d-none d-md-block text-end" style="min-width:6.5rem;">
                        <div class="text-sm">{{ ($row->paid_at ?? $row->created_at)?->format('d M Y') }}</div>
                        <div class="text-3xs text-faint">{{ $row->recordedBy?->name ?? 'automatic' }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($rows->hasPages())
            <div class="mt-3">{{ $rows->links() }}</div>
        @endif
    @else
        <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
            <span class="d-inline-flex align-items-center justify-content-center rounded-circle person-avatar mx-auto mb-3"
                  style="width:3.25rem;height:3.25rem;font-size:1.2rem;"><i class="bi bi-receipt"></i></span>
            <h2 class="h5 fw-semibold font-display">No payments yet</h2>
            <p class="text-muted-foreground mb-0">
                Entries appear here as payments are recorded — by you or by the gateway.
            </p>
        </div>
    @endif
</div>
@endsection

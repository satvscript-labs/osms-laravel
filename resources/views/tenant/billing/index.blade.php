@extends('layouts.app')
@section('title', 'Billing & plans')

@section('content')
<div class="p-4 p-md-5">
    <div class="mb-4">
        <p class="section-label mb-1">Account</p>
        <h1 class="h3 fw-semibold font-display mb-1">Billing &amp; plans</h1>
        <p class="text-muted-foreground mb-0" style="font-size:.9rem;">Manage your OSMS subscription.</p>
    </div>

    @if (session('status'))
        <div class="alert alert-success py-2 px-3 small rounded-3">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger py-2 px-3 small rounded-3">{{ session('error') }}</div>
    @endif

    @php
        $s = $subscription?->status;
        $state = $subscription?->accessState();
    @endphp

    {{-- Current subscription --}}
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <p class="section-label mb-1">Current plan</p>
                <div class="d-flex align-items-center gap-2">
                    <span class="h4 fw-semibold font-display mb-0 text-capitalize">
                        {{ $subscription?->tier ?? 'None' }}
                    </span>
                    <span class="badge {{ in_array($s, ['active','trialing']) ? 'text-bg-success' : ($s === 'past_due' ? 'text-bg-warning' : 'text-bg-secondary') }}">
                        {{ $s ?? 'inactive' }}
                    </span>
                </div>
                @if ($subscription?->cancel_at_period_end && $subscription?->current_period_end)
                    <p class="text-warning-emphasis mb-0 mt-1" style="font-size:.82rem;">
                        <i class="bi bi-clock-history me-1"></i>
                        Cancels on {{ $subscription->current_period_end->format('d M Y') }}
                    </p>
                @elseif ($subscription?->current_period_end)
                    <p class="text-muted-foreground mb-0 mt-1" style="font-size:.82rem;">
                        {{ $subscription->status === 'trialing' ? 'Trial ends' : 'Renews' }}
                        {{ $subscription->current_period_end->format('d M Y') }}
                    </p>
                @endif
            </div>

            @if ($s === 'active' && ! $subscription?->cancel_at_period_end)
                <form method="POST" action="{{ route('tenant.billing.cancel') }}">
                    @csrf
                    <button type="button" class="btn btn-light"
                            data-confirm="Your subscription stays active until the end of the current billing period, then it won't renew. You can resubscribe anytime."
                            data-confirm-title="Cancel subscription?"
                            data-confirm-label="Cancel subscription"
                            data-confirm-tone="danger">
                        Cancel subscription
                    </button>
                </form>
            @endif
        </div>
    </div>

    @unless ($configured)
        <div class="alert alert-info py-2 px-3 small rounded-3">
            <i class="bi bi-info-circle me-1"></i>
            Online payments aren't configured yet. Add your Razorpay keys to <code>.env</code> to enable checkout.
        </div>
    @endunless

    {{-- Plan --}}
    @php
        $basic = $plans['basic'];
        $save = (int) round((1 - $basic['yearly_price'] / ($basic['monthly_price'] * 12)) * 100);
    @endphp
    <div class="row g-3" x-data="{ interval: 'monthly' }">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100" style="outline:2px solid var(--osms-primary);">
                <div class="card-body p-4 d-flex flex-column">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                        <h2 class="h5 fw-semibold font-display mb-0">{{ $basic['name'] }}</h2>
                        <div class="segmented">
                            <button type="button" class="segmented-item" :class="{ active: interval === 'monthly' }" @click="interval = 'monthly'">Monthly</button>
                            <button type="button" class="segmented-item" :class="{ active: interval === 'yearly' }" @click="interval = 'yearly'">
                                Yearly
                                @if ($save > 0)<span class="badge text-bg-success">Save {{ $save }}%</span>@endif
                            </button>
                        </div>
                    </div>

                    <p class="mb-3">
                        <span x-show="interval === 'monthly'" x-cloak>
                            <span class="h3 fw-semibold font-display">₹{{ number_format($basic['monthly_price']) }}</span>
                            <span class="text-muted-foreground">/mo</span>
                        </span>
                        <span x-show="interval === 'yearly'" x-cloak>
                            <span class="h3 fw-semibold font-display">₹{{ number_format($basic['yearly_price']) }}</span>
                            <span class="text-muted-foreground">/yr</span>
                        </span>
                    </p>

                    <ul class="list-unstyled d-flex flex-column gap-2 mb-4 flex-grow-1" style="font-size:.88rem;">
                        @foreach ($basic['features'] as $f)
                            <li><i class="bi bi-check-circle-fill text-primary me-2"></i>{{ $f }}</li>
                        @endforeach
                    </ul>

                    <form method="POST" action="{{ route('tenant.billing.subscribe') }}">
                        @csrf
                        <input type="hidden" name="tier" value="basic">
                        <input type="hidden" name="interval" :value="interval">
                        <button type="submit" class="btn btn-primary w-100" {{ $s === 'active' ? 'disabled' : '' }}>
                            {{ $s === 'active' ? 'Current plan' : 'Subscribe' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Invoices --}}
    <div class="mt-5">
        <p class="section-label mb-2">Payment history</p>
        @if ($invoices->isEmpty())
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4 text-center text-muted-foreground" style="font-size:.88rem;">
                    <i class="bi bi-receipt d-block fs-4 mb-2 text-faint"></i>
                    No payments yet. Invoices appear here after your first subscription charge.
                </div>
            </div>
        @else
            <div class="card border-0 shadow-sm rounded-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0" style="font-size:.88rem;">
                        <thead class="text-muted-foreground">
                            <tr>
                                <th class="ps-4">Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Invoice</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoices as $invoice)
                                <tr>
                                    <td class="ps-4">{{ optional($invoice->paid_at ?? $invoice->created_at)->format('d M Y') }}</td>
                                    <td>₹ {{ number_format($invoice->amount, 2) }}</td>
                                    <td><span class="badge text-bg-success">{{ $invoice->status }}</span></td>
                                    <td class="text-end pe-4">
                                        <a href="{{ route('tenant.billing.invoices.pdf', $invoice) }}"
                                           class="btn btn-sm btn-light">
                                            <i class="bi bi-download me-1"></i> PDF
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

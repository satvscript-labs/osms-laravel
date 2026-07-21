@extends('layouts.app')
@section('title', 'Checkout')

@section('content')
<div class="p-4 p-md-5 text-center" style="max-width:32rem;">
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-5">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary mb-3"
                  style="width:3rem;height:3rem;"><i class="bi bi-credit-card fs-4"></i></span>
            <h1 class="h4 fw-semibold font-display mb-2">Complete your subscription</h1>
            <p class="text-muted-foreground mb-4">You're subscribing to the <strong class="text-capitalize">{{ $tier }}</strong> plan.</p>
            <button id="payBtn" class="btn btn-primary btn-lg px-4"><i class="bi bi-lock me-1"></i> Pay with Razorpay</button>
            <p class="text-muted-foreground mt-3 mb-0" style="font-size:var(--text-sm);">Secured by Razorpay</p>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script nonce="{{ csp_nonce() }}">
    const options = {
        key: @json($razorpayKey),
        subscription_id: @json($subscriptionId),
        name: 'OSMS',
        description: @json(ucfirst($tier) . ' plan subscription'),
        prefill: { name: @json($user->name), email: @json($user->email) },
        theme: { color: '#004f75' },
        handler: function () { window.location = @json(route('tenant.billing.success')); },
    };
    const rzp = new Razorpay(options);
    document.getElementById('payBtn').addEventListener('click', () => rzp.open());
    // Auto-open on load
    rzp.open();
</script>
@endpush
@endsection

@extends('layouts.app')
@section('title', 'Pay your bill')

@section('content')
{{--
    E3 — Razorpay Checkout for ONE bill (an Order for exactly its amount, read from
    the database). The browser only ever sends Razorpay's three ids back to us; the
    amount shown here is for the person, the amount charged is the order's.
--}}
<div class="p-4 p-md-5 d-flex justify-content-center">
    <div class="card border-0 shadow-sm rounded-4 animate-fade-up w-100" style="max-width:32rem;">
        <div class="card-body p-4 p-md-5 text-center">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 mb-3"
                  style="width:3rem;height:3rem;background:var(--osms-primary-soft);color:var(--osms-primary);">
                <i class="bi bi-credit-card"></i>
            </span>
            <p class="section-label mb-1">{{ $bill->number }}</p>
            <h1 class="h4 fw-semibold font-display mb-1">{{ $bill->label() }}</h1>
            <p class="h3 fw-semibold font-display mb-3">₹ {{ number_format($bill->amount, 2) }}</p>

            @include('partials.charge-working', ['invoice' => $bill])

            <button id="payBtn" type="button" class="btn btn-primary btn-lg px-4 mt-4">
                <i class="bi bi-lock me-1"></i> Pay ₹ {{ number_format($bill->amount, 2) }}
            </button>
            <p class="text-muted-foreground text-sm mt-3 mb-0">
                Secured by Razorpay · <a href="{{ route('tenant.billing.index') }}">Back to Subscription</a>
            </p>

            {{-- Razorpay's three ids come back through this form (CSRF-protected). --}}
            <form id="paidForm" method="POST" action="{{ route('tenant.billing.paid') }}" class="d-none">
                @csrf
                <input type="hidden" name="razorpay_order_id">
                <input type="hidden" name="razorpay_payment_id">
                <input type="hidden" name="razorpay_signature">
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script nonce="{{ csp_nonce() }}">
    (function () {
        const form = document.getElementById('paidForm');
        const rzp = new Razorpay({
            key: @json($razorpayKey),
            order_id: @json($orderId),
            name: 'OSMS',
            description: @json($bill->number . ' · ' . $bill->label()),
            prefill: { name: @json($user->name), email: @json($user->email) },
            theme: { color: getComputedStyle(document.documentElement).getPropertyValue('--osms-primary').trim() || undefined },
            handler: function (response) {
                form.razorpay_order_id.value = response.razorpay_order_id;
                form.razorpay_payment_id.value = response.razorpay_payment_id;
                form.razorpay_signature.value = response.razorpay_signature;
                form.submit();
            },
        });
        document.getElementById('payBtn').addEventListener('click', () => rzp.open());
        rzp.open();
    })();
</script>
@endpush
@endsection

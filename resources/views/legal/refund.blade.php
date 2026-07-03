@extends('legal.layout')
@section('heading', 'Refund & Cancellation Policy')
@section('content')
    <p>This policy explains billing, cancellation, and refunds for OSMS subscriptions operated by
    {{ config('saas.legal_entity') }}.</p>

    <h2 class="h5 font-display mt-4">1. Free trial</h2>
    <p>New stores get a {{ config('billing.trial_days', 14) }}-day free trial with no payment required.
    You will not be charged unless you choose to subscribe.</p>

    <h2 class="h5 font-display mt-4">2. Billing cycle</h2>
    <p>Paid subscriptions are billed in advance on a recurring monthly basis via Razorpay. Applicable
    taxes (GST) are added as required.</p>

    <h2 class="h5 font-display mt-4">3. Cancellation</h2>
    <p>You may cancel at any time from the in-app Billing page. Cancellation stops future renewals; your
    subscription remains active until the end of the current paid period, after which access is
    suspended. Your data is retained for 30 days so you can export it or resubscribe.</p>

    <h2 class="h5 font-display mt-4">4. Refunds</h2>
    <p>Because you can trial the Service free before paying and can cancel anytime, subscription fees for
    the current billing period are generally <strong>non-refundable</strong>. If you were charged in
    error or experienced a material service failure, contact us within 7 days and we will review your
    case in good faith. <em>[Confirm your final refund stance with counsel before launch.]</em></p>

    <h2 class="h5 font-display mt-4">5. Failed payments</h2>
    <p>If a renewal payment fails, we retry for a short grace period. If it remains unpaid, access is
    suspended until the subscription is renewed.</p>

    <h2 class="h5 font-display mt-4">6. Contact</h2>
    <p>Billing questions or refund requests: <a href="{{ route('legal.contact') }}">Contact us</a>.</p>
@endsection

@extends('legal.layout')
@section('heading', 'Terms of Service')
@section('content')
    <p>These Terms of Service ("Terms") govern your access to and use of OSMS ("the Service"),
    operated by {{ config('saas.legal_entity') }} ("we", "us"). By creating an account or using the
    Service, you agree to these Terms.</p>

    <h2 class="h5 font-display mt-4">1. The Service</h2>
    <p>OSMS is a multi-tenant software-as-a-service application for optical retail store management,
    including customer and prescription records, inventory, point-of-sale, order tracking, and
    analytics.</p>

    <h2 class="h5 font-display mt-4">2. Accounts &amp; eligibility</h2>
    <p>You must provide accurate registration details and are responsible for all activity under your
    account and for keeping your credentials secure. You must be authorised to operate the business
    you register.</p>

    <h2 class="h5 font-display mt-4">3. Free trial &amp; subscriptions</h2>
    <p>New stores receive a {{ config('billing.trial_days', 14) }}-day free trial. After the trial you
    must subscribe to a paid plan to continue using the Service. Fees, billing cycle, and cancellation
    are described in our <a href="{{ route('legal.refund') }}">Refund &amp; Cancellation Policy</a>. If
    a subscription lapses, access is suspended until it is renewed.</p>

    <h2 class="h5 font-display mt-4">4. Your data</h2>
    <p>You retain ownership of the data you enter (including customer and prescription records). You are
    responsible for having a lawful basis to collect and store your customers' information and for the
    accuracy of that data. Our handling of personal data is described in our
    <a href="{{ route('legal.privacy') }}">Privacy Policy</a>.</p>

    <h2 class="h5 font-display mt-4">5. Acceptable use</h2>
    <p>You agree not to misuse the Service, attempt to access other tenants' data, disrupt the platform,
    or use it for unlawful purposes.</p>

    <h2 class="h5 font-display mt-4">6. Availability &amp; changes</h2>
    <p>We aim for high availability but do not guarantee uninterrupted service. We may modify or
    discontinue features with reasonable notice.</p>

    <h2 class="h5 font-display mt-4">7. Limitation of liability</h2>
    <p>The Service is provided "as is". To the maximum extent permitted by law, our liability is limited
    to the fees you paid in the preceding three months. <em>[Confirm with counsel.]</em></p>

    <h2 class="h5 font-display mt-4">8. Termination</h2>
    <p>You may close your account at any time. We may suspend or terminate accounts that breach these
    Terms. On termination, data is retained then deleted per our Privacy Policy.</p>

    <h2 class="h5 font-display mt-4">9. Governing law</h2>
    <p>These Terms are governed by the laws of India, with jurisdiction in the courts of
    {{ config('saas.legal_entity') }}'s registered location. <em>[Confirm jurisdiction with counsel.]</em></p>

    <h2 class="h5 font-display mt-4">10. Contact</h2>
    <p>Questions about these Terms: <a href="{{ route('legal.contact') }}">Contact us</a>.</p>
@endsection

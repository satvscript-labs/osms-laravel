@extends('legal.layout')
@section('heading', 'Privacy Policy')
@section('content')
    <p>This Privacy Policy explains how {{ config('saas.legal_entity') }} ("we") handles personal data in
    OSMS ("the Service"), in line with India's Digital Personal Data Protection Act, 2023 (DPDP Act).</p>

    <h2 class="h5 font-display mt-4">1. Roles</h2>
    <p>For data a store enters about its own customers (names, phone numbers, ages, prescriptions,
    purchase history), the <strong>store is the data fiduciary/controller</strong> and OSMS acts as a
    <strong>data processor</strong> on the store's instructions. For account data of the store's own
    users (name, email), we are the controller.</p>

    <h2 class="h5 font-display mt-4">2. What we collect</h2>
    <ul>
        <li><strong>Account data:</strong> your name, email, password (hashed), and store details.</li>
        <li><strong>Store operational data:</strong> customer records, prescriptions (health-related
        data), inventory, orders, and payments you enter.</li>
        <li><strong>Billing data:</strong> subscription status and payment references (card/UPI details
        are handled by our payment processor, not stored by us).</li>
        <li><strong>Technical data:</strong> logs needed to operate and secure the Service.</li>
    </ul>

    <h2 class="h5 font-display mt-4">3. How we use it</h2>
    <p>To provide and secure the Service, process subscriptions, provide support, and comply with law.
    We do not sell personal data.</p>

    <h2 class="h5 font-display mt-4">4. Tenant isolation</h2>
    <p>Each store's data is logically isolated; one store cannot access another store's records.</p>

    <h2 class="h5 font-display mt-4">5. Sub-processors</h2>
    <ul>
        <li><strong>Razorpay</strong> — payment processing for subscriptions.</li>
        <li><strong>Hosting provider</strong> — infrastructure hosting in India.</li>
    </ul>

    <h2 class="h5 font-display mt-4">6. Retention &amp; deletion</h2>
    <p>Operational records you delete are soft-deleted and permanently purged after 30 days. When a store
    closes its account, its data is retained for 30 days (to allow recovery/export) and then permanently
    deleted, unless a longer period is required by law.</p>

    <h2 class="h5 font-display mt-4">7. Your rights</h2>
    <p>Subject to the DPDP Act, you may access or correct your account data, and request a copy or
    deletion of your store's data by <a href="{{ route('legal.contact') }}">contacting us</a>. Store
    customers should direct such requests to the store; we assist the store in fulfilling them.</p>

    <h2 class="h5 font-display mt-4">8. Security</h2>
    <p>We use industry-standard measures (encryption in transit, hashed passwords, access controls). No
    system is perfectly secure; we will notify affected users and the Data Protection Board of material
    breaches as required.</p>

    <h2 class="h5 font-display mt-4">9. Contact / Grievance Officer</h2>
    <p>For privacy requests or complaints: <a href="{{ route('legal.contact') }}">Contact us</a>.
    <em>[Name a Grievance Officer before launch, as required by the DPDP Act.]</em></p>
@endsection

@extends('legal.layout')
@section('heading', 'Contact Us')
@section('content')
    <p>We're here to help with any question about OSMS — your account, billing, or the Service.</p>

    <div class="d-flex flex-column gap-3 mt-4">
        <div class="d-flex align-items-start gap-3">
            <i class="bi bi-envelope-fill text-primary fs-5"></i>
            <div>
                <div class="fw-semibold">Email</div>
                <a href="mailto:{{ config('saas.support_email') }}">{{ config('saas.support_email') }}</a>
            </div>
        </div>
        <div class="d-flex align-items-start gap-3">
            <i class="bi bi-building text-primary fs-5"></i>
            <div>
                <div class="fw-semibold">{{ config('saas.legal_entity') }}</div>
                <div class="text-muted-foreground">{{ config('saas.contact_address') }}</div>
            </div>
        </div>
        <div class="d-flex align-items-start gap-3">
            <i class="bi bi-receipt text-primary fs-5"></i>
            <div>
                <div class="fw-semibold">GSTIN</div>
                <div class="text-muted-foreground">{{ config('saas.gst_number') }}</div>
            </div>
        </div>
    </div>

    <p class="mt-4">For privacy or data-protection requests, see our
    <a href="{{ route('legal.privacy') }}">Privacy Policy</a>.</p>
@endsection

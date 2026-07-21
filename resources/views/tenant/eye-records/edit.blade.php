@extends('layouts.app')
@section('title', 'Edit eye record')

@section('content')
<div class="p-4 p-md-5" style="max-width:72rem;">
    {{-- Breadcrumb --}}
    <a href="{{ route('tenant.customers.show', $customer) }}"
       class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3" style="font-size:var(--text-sm);">
        <i class="bi bi-chevron-left"></i> Back to customer
    </a>

    {{-- Header --}}
    <div class="mb-4">
        <p class="section-label mb-1">Prescription</p>
        <h1 class="h3 fw-semibold font-display mb-2">Edit eye prescription</h1>
        <p class="text-muted-foreground mb-0" style="font-size:var(--text-md);">
            Updating the {{ $record->created_at->format('d M Y') }} record for
            <span class="fw-medium">{{ $customer->name }}</span>
        </p>
    </div>

    @include('tenant.eye-records._form', ['customer' => $customer, 'record' => $record])
</div>
@endsection

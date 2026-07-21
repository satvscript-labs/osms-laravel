@extends('layouts.app')
@section('title', 'Edit ' . $customer->name)

@section('content')
<div class="p-4 p-md-5" style="max-width:48rem;">
    <a href="{{ route('tenant.customers.show', $customer) }}"
       class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3" style="font-size:var(--text-sm);">
        <i class="bi bi-chevron-left"></i> Back to customer
    </a>

    <p class="section-label mb-1">Edit customer</p>
    <h1 class="h3 fw-semibold font-display mb-4">{{ $customer->name }}</h1>

    @include('tenant.customers._form', ['customer' => $customer])
</div>
@endsection

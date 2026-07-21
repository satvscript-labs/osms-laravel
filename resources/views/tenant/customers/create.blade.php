@extends('layouts.app')
@section('title', 'New customer')

@section('content')
<div class="p-4 p-md-5" style="max-width:48rem;">
    <a href="{{ route('tenant.customers.index') }}"
       class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3" style="font-size:var(--text-sm);">
        <i class="bi bi-chevron-left"></i> Back to customers
    </a>

    <p class="section-label mb-1">New customer</p>
    <h1 class="h3 fw-semibold font-display mb-4">Add customer</h1>

    @include('tenant.customers._form', ['customer' => null])
</div>
@endsection

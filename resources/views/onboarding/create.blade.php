@extends('layouts.guest')
@section('title', 'Set up your store')

@section('content')
    <h1 class="h4 fw-semibold font-display mb-1">Set up your store</h1>
    <p class="text-muted-foreground mb-3" style="font-size:.875rem;">
        A few details to get your workspace ready.
    </p>

    <div class="alert alert-info d-flex align-items-center gap-2 py-2 px-3 rounded-3" style="font-size:.82rem;">
        <i class="bi bi-stars"></i>
        <div>Your <strong>{{ config('billing.trial_days', 14) }}-day free trial</strong> starts now — no card required.</div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger py-2 px-3 small rounded-3">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('onboarding.store') }}" enctype="multipart/form-data"
          class="d-flex flex-column gap-3">
        @csrf

        <div>
            <label for="store_name" class="form-label small fw-medium mb-1">Store name *</label>
            <input id="store_name" type="text" name="store_name" value="{{ old('store_name') }}"
                   class="form-control @error('store_name') is-invalid @enderror"
                   required autofocus placeholder="Sahaj Optical">
        </div>

        <div>
            <label for="tax_id" class="form-label small fw-medium mb-1">GST / Tax ID</label>
            <input id="tax_id" type="text" name="tax_id" value="{{ old('tax_id') }}"
                   class="form-control @error('tax_id') is-invalid @enderror" placeholder="22AAAAA0000A1Z5"
                   maxlength="15" style="text-transform:uppercase;"
                   oninput="this.value=this.value.toUpperCase().replace(/[^0-9A-Z]/g,'').slice(0,15)">
            @error('tax_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div>
            <label for="address" class="form-label small fw-medium mb-1">Address</label>
            <input id="address" type="text" name="address" value="{{ old('address') }}"
                   class="form-control" placeholder="123 Main Street, Mumbai">
        </div>

        <div>
            <label for="logo" class="form-label small fw-medium mb-1">Store logo</label>
            <input id="logo" type="file" name="logo" accept="image/*"
                   class="form-control @error('logo') is-invalid @enderror">
            <div class="form-text" style="font-size:.75rem;">
                Used on printed receipts. PNG or JPG, ideally square. Max 2MB.
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 fw-medium mt-1">Complete setup</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="text-center mt-3">
        @csrf
        <button type="submit" class="btn btn-link text-decoration-none small text-muted-foreground">
            Sign out
        </button>
    </form>
@endsection

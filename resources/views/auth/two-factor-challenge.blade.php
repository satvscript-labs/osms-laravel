@extends('layouts.guest')
@section('title', 'Two-factor verification')

@section('content')
<div class="glass rounded-4 p-4 p-md-5 shadow-sm animate-fade-up" style="max-width: 26rem; width: 100%;">
    <div class="text-center mb-4">
        <span class="d-inline-flex align-items-center justify-content-center rounded-4 bg-primary text-white mb-3"
              style="width:3rem;height:3rem;"><i class="bi bi-shield-lock fs-4"></i></span>
        <h1 class="h4 fw-semibold font-display mb-1">Two-factor verification</h1>
        <p class="text-muted-foreground mb-0 text-sm">
            Enter the 6-digit code from your authenticator app.
        </p>
    </div>

    @if (session('status'))
        <div class="alert alert-success py-2 text-sm">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('two-factor.verify') }}">
        @csrf
        <div class="mb-3">
            <label for="code" class="form-label">Authentication code</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                   class="form-control form-control-lg text-center @error('code') is-invalid @enderror"
                   style="letter-spacing: .35em;" maxlength="11" autofocus required
                   placeholder="000000">
            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <p class="text-muted-foreground mt-2 mb-0" style="font-size:.75rem;">
                Lost your device? Enter one of your recovery codes instead.
            </p>
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-check-lg me-1"></i> Verify
        </button>
    </form>

    <form method="POST" action="{{ route('two-factor.cancel') }}" class="mt-3 text-center">
        @csrf
        <button type="submit" class="btn btn-link text-muted-foreground p-0 text-sm">
            Sign in as a different user
        </button>
    </form>
</div>
@endsection

@extends('layouts.app')
@section('title', 'Two-factor authentication')

@section('content')
<div class="p-4 p-md-5" style="max-width: 44rem;">
    <a href="{{ route('profile.edit') }}"
       class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3 text-sm">
        <i class="bi bi-chevron-left"></i> Back to profile
    </a>

    <div class="card card-lift rounded-4 shadow-sm">
        <div class="card-body p-4 p-md-5">
            <h1 class="h4 fw-semibold font-display mb-1">Set up two-factor authentication</h1>
            <p class="text-muted-foreground text-sm">
                Adds a second step at sign-in, so a stolen password alone is not enough to get in.
            </p>

            @if (session('error'))
                <div class="alert alert-warning py-2 text-sm">{{ session('error') }}</div>
            @endif

            <ol class="mt-4 ps-3" style="line-height: 2;">
                <li class="text-sm">Install an authenticator app (Google Authenticator, Authy, 1Password).</li>
                <li class="text-sm">Scan this QR code, or enter the setup key by hand.</li>
                <li class="text-sm">Enter the 6-digit code it shows to confirm.</li>
            </ol>

            <div class="d-flex flex-column flex-md-row gap-4 align-items-center my-4 p-3 rounded-3"
                 style="background: var(--surface-sunken);">
                <div class="bg-white p-2 rounded-3 flex-shrink-0">
                    {!! QrCode::size(168)->margin(0)->generate($uri) !!}
                </div>
                <div class="min-w-0">
                    <p class="section-label mb-1">Setup key</p>
                    <code class="d-block text-break" style="font-size:.8rem;">{{ $secret }}</code>
                    <p class="text-muted-foreground mb-0 mt-2" style="font-size:.72rem;">
                        Use this if you can't scan the code. Treat it like a password.
                    </p>
                </div>
            </div>

            <form method="POST" action="{{ route('two-factor.confirm') }}">
                @csrf
                <label for="code" class="form-label">Confirmation code</label>
                <div class="d-flex gap-2">
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                           class="form-control @error('code') is-invalid @enderror"
                           style="max-width: 12rem; letter-spacing:.25em;" maxlength="7" required
                           placeholder="000000" autofocus>
                    <button type="submit" class="btn btn-primary text-nowrap">
                        <i class="bi bi-shield-check me-1"></i> Turn on
                    </button>
                </div>
                @error('code')<div class="text-danger mt-1 text-sm">{{ $message }}</div>@enderror
            </form>
        </div>
    </div>
</div>
@endsection

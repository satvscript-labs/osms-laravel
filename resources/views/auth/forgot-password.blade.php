@extends('layouts.guest')
@section('title', 'Reset password')

@section('content')
    <h1 class="h4 fw-semibold font-display mb-1">Forgot password?</h1>
    <p class="text-muted-foreground mb-4" style="font-size:var(--text-md);">
        Enter your email and we'll send you a reset link.
    </p>

    @if (session('status'))
        <div class="alert alert-success py-2 px-3 small rounded-3">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger py-2 px-3 small rounded-3">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="d-flex flex-column gap-3">
        @csrf
        <div>
            <label for="email" class="form-label small fw-medium mb-1">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="email" placeholder="you@store.com">
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-medium">Email reset link</button>
        <p class="text-center mb-0 small">
            <a href="{{ route('login') }}" class="text-decoration-none">Back to sign in</a>
        </p>
    </form>
@endsection

@extends('layouts.guest')
@section('title', 'Sign in')

@section('content')
    <h1 class="h4 fw-semibold font-display mb-1">Welcome back</h1>
    <p class="text-muted-foreground mb-4" style="font-size:var(--text-md);">Sign in to your store workspace.</p>

    @if (session('status'))
        <div class="alert alert-success py-2 px-3 small rounded-3">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger py-2 px-3 small rounded-3">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="d-flex flex-column gap-3">
        @csrf

        <div>
            <label for="email" class="form-label small fw-medium mb-1">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="email" placeholder="you@store.com">
        </div>

        <div>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <label for="password" class="form-label small fw-medium mb-0">Password</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="small text-decoration-none">Forgot?</a>
                @endif
            </div>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="current-password" placeholder="••••••••">
        </div>

        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" id="remember">
            <label class="form-check-label small" for="remember">Remember me</label>
        </div>

        <button type="submit" class="btn btn-primary w-100 fw-medium">Sign in</button>

        <p class="text-center text-muted-foreground mb-0 small">
            No account?
            <a href="{{ route('register') }}" class="fw-medium text-decoration-none">Create one</a>
        </p>
    </form>
@endsection

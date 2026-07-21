@extends('layouts.guest')
@section('title', 'Create account')

@section('content')
    <h1 class="h4 fw-semibold font-display mb-1">Create your account</h1>
    <p class="text-muted-foreground mb-4" style="font-size:var(--text-md);">Start managing your optical store.</p>

    @if ($errors->any())
        <div class="alert alert-danger py-2 px-3 small rounded-3">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}" class="d-flex flex-column gap-3">
        @csrf

        <div>
            <label for="name" class="form-label small fw-medium mb-1">Full name</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}"
                   class="form-control @error('name') is-invalid @enderror"
                   required autofocus autocomplete="name" placeholder="Jane Doe">
        </div>

        <div>
            <label for="email" class="form-label small fw-medium mb-1">Work email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autocomplete="email" placeholder="you@store.com">
        </div>

        <div>
            <label for="password" class="form-label small fw-medium mb-1">Password</label>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="new-password" placeholder="Minimum 8 characters">
        </div>

        <div>
            <label for="password_confirmation" class="form-label small fw-medium mb-1">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   class="form-control" required autocomplete="new-password" placeholder="Re-enter password">
        </div>

        <button type="submit" class="btn btn-primary w-100 fw-medium">Create account</button>

        <p class="text-center text-muted-foreground mb-0 small">
            Already have an account?
            <a href="{{ route('login') }}" class="fw-medium text-decoration-none">Sign in</a>
        </p>
    </form>
@endsection

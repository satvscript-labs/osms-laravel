@extends('layouts.guest')
@section('title', 'Set new password')

@section('content')
    <h1 class="h4 fw-semibold font-display mb-1">Set a new password</h1>
    <p class="text-muted-foreground mb-4" style="font-size:var(--text-md);">Choose a strong password for your account.</p>

    @if ($errors->any())
        <div class="alert alert-danger py-2 px-3 small rounded-3">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.store') }}" class="d-flex flex-column gap-3">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <label for="email" class="form-label small fw-medium mb-1">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="email">
        </div>
        <div>
            <label for="password" class="form-label small fw-medium mb-1">New password</label>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="new-password">
        </div>
        <div>
            <label for="password_confirmation" class="form-label small fw-medium mb-1">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   class="form-control" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-medium">Reset password</button>
    </form>
@endsection

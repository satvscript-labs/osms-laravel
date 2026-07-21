@extends('layouts.guest')
@section('title', 'Confirm password')

@section('content')
    <h1 class="h4 fw-semibold font-display mb-1">Confirm password</h1>
    <p class="text-muted-foreground mb-4" style="font-size:var(--text-md);">
        This is a secure area. Please confirm your password to continue.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger py-2 px-3 small rounded-3">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.confirm') }}" class="d-flex flex-column gap-3">
        @csrf
        <div>
            <label for="password" class="form-label small fw-medium mb-1">Password</label>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="current-password" autofocus>
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-medium">Confirm</button>
    </form>
@endsection

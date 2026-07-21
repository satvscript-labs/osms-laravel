@extends('layouts.guest')
@section('title', 'Verify email')

@section('content')
    <h1 class="h4 fw-semibold font-display mb-1">Verify your email</h1>
    <p class="text-muted-foreground mb-4" style="font-size:var(--text-md);">
        Thanks for signing up! Please verify your email by clicking the link we just sent you.
        If you didn't receive it, we'll gladly send another.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success py-2 px-3 small rounded-3">
            A new verification link has been sent to your email address.
        </div>
    @endif

    <div class="d-flex align-items-center justify-content-between gap-2">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary fw-medium">Resend verification email</button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-link text-decoration-none small">Log out</button>
        </form>
    </div>
@endsection

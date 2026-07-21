@extends('layouts.guest')
@section('title', 'Accept invitation')

@section('content')
    <div class="text-center mb-4">
        <h1 class="h4 fw-semibold font-display mb-1">Join {{ $tenant?->store_name ?? 'the team' }}</h1>
        <p class="text-muted-foreground mb-0" style="font-size:var(--text-md);">
            You've been invited as {{ $invitation->role === 'store_admin' ? 'an admin' : 'a staff member' }}.
            Set your password to get started.
        </p>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger py-2 px-3 small rounded-3">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('invitations.accept', $invitation->token) }}" class="d-flex flex-column gap-3">
        @csrf

        <div>
            <label class="form-label small fw-medium">Email</label>
            <input type="email" class="form-control" value="{{ $invitation->email }}" disabled>
        </div>
        <div>
            <label class="form-label small fw-medium">Your name</label>
            <input type="text" name="name" class="form-control" value="{{ old('name') }}" required autofocus>
        </div>
        <div>
            <label class="form-label small fw-medium">Password</label>
            <input type="password" name="password" class="form-control" required autocomplete="new-password">
        </div>
        <div>
            <label class="form-label small fw-medium">Confirm password</label>
            <input type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary w-100">Join {{ $tenant?->store_name ?? 'store' }}</button>
    </form>
@endsection

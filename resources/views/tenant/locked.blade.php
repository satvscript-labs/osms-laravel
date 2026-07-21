@extends('layouts.app')
@section('title', 'Subscription paused')

@section('content')
@php
    $message = match ($subscription?->status) {
        'trialing' => 'This store\'s free trial has ended.',
        'past_due' => 'This store\'s payment is overdue, so access is paused.',
        default    => 'This store\'s subscription is inactive.',
    };
@endphp

<div class="p-4 p-md-5">
    <div class="glass card-lift rounded-4 text-center mx-auto animate-fade-up p-5" style="max-width:32rem;">
        <span class="d-inline-flex align-items-center justify-content-center rounded-4 mb-3"
              style="width:3.5rem;height:3.5rem;background:var(--tone-amber-bg);color:var(--tone-amber);">
            <i class="bi bi-lock fs-3"></i>
        </span>

        <h1 class="h4 fw-semibold font-display mb-2">Workspace paused</h1>
        <p class="text-muted-foreground mb-4">{{ $message }}</p>

        <div class="rounded-3 p-3 text-start mb-4" style="background: var(--surface-sunken);">
            <p class="section-label mb-2">What happens now</p>
            <p class="mb-0 text-sm text-muted-foreground">
                Your work is safe — nothing has been deleted. A store admin needs to renew the
                subscription to unlock the workspace again.
            </p>
        </div>

        @if ($admins->isNotEmpty())
            <p class="section-label mb-2">Who can unlock it</p>
            <ul class="list-unstyled mb-4">
                @foreach ($admins as $admin)
                    <li class="text-sm">
                        <span class="fw-medium">{{ $admin->name }}</span>
                        <span class="text-muted-foreground">· {{ $admin->email }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-light">
                <i class="bi bi-box-arrow-right me-1"></i> Sign out
            </button>
        </form>
    </div>
</div>
@endsection

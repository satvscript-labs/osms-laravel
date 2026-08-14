@extends('layouts.app')
@section('title', 'Workspace paused')

@php
    /**
     * ISS-01 — the copy follows the REAL reason, not the status field.
     *
     * A store the operator suspended is paid up. Telling them "your payment is
     * overdue, please renew" invited a second payment for something no payment
     * could fix. Each state below says what is true and gives exactly one next
     * step that can actually work.
     *
     * ⚠ The operator's own `override_reason` is deliberately NOT shown. It is
     * an internal note written for the audit trail ("abuse", "cheque bounced")
     * and is not addressed to the customer.
     */
    $copy = match ($reason) {
        'closed' => [
            'icon' => 'bi-archive',
            'tone' => 'red',
            'title' => 'This store has been closed',
            'body' => 'Your store is no longer active. Your data is retained for a limited period, so if this was not expected please get in touch as soon as possible.',
            'next' => 'contact',
        ],
        'suspended', 'store_suspended' => [
            'icon' => 'bi-pause-circle',
            'tone' => 'amber',
            'title' => 'Your access has been paused',
            'body' => 'Access to this store has been paused by our team. This is not a billing problem — your subscription is unaffected and nothing has been deleted. Please contact us and we will sort it out.',
            'next' => 'contact',
        ],
        'cancelled_by_us' => [
            'icon' => 'bi-x-octagon',
            'tone' => 'red',
            'title' => 'Your subscription has been ended',
            'body' => 'Your subscription was ended by our team. Nothing has been deleted. Please contact us if you would like to discuss it or start again.',
            'next' => 'contact',
        ],
        'trial_ended' => [
            'icon' => 'bi-hourglass-bottom',
            'tone' => 'amber',
            'title' => 'Your free trial has ended',
            'body' => 'Your work is safe — nothing has been deleted. A store admin needs to subscribe to unlock the workspace again.',
            'next' => 'admins',
        ],
        'payment_overdue' => [
            'icon' => 'bi-credit-card',
            'tone' => 'amber',
            'title' => 'Payment is overdue',
            'body' => 'Your last payment did not go through, so access is paused. Your work is safe — nothing has been deleted. A store admin can settle it to unlock the workspace.',
            'next' => 'admins',
        ],
        default => [
            'icon' => 'bi-lock',
            'tone' => 'amber',
            'title' => 'Workspace paused',
            'body' => 'This store\'s subscription is not active. Your work is safe — nothing has been deleted. A store admin can restart the subscription to unlock the workspace.',
            'next' => 'admins',
        ],
    };
@endphp

@section('content')
<div class="p-4 p-md-5">
    <div class="glass card-lift rounded-4 text-center mx-auto animate-fade-up p-5" style="max-width:32rem;">
        <span class="d-inline-flex align-items-center justify-content-center rounded-4 mb-3"
              style="width:3.5rem;height:3.5rem;
                     background:var(--tone-{{ $copy['tone'] }}-bg);
                     color:var(--tone-{{ $copy['tone'] }});">
            <i class="bi {{ $copy['icon'] }} fs-3"></i>
        </span>

        <h1 class="h4 fw-semibold font-display mb-2">{{ $copy['title'] }}</h1>
        <p class="text-muted-foreground mb-4">{{ $copy['body'] }}</p>

        @if ($copy['next'] === 'contact')
            {{-- Nothing they can pay for — give them the one route that works. --}}
            <div class="rounded-3 p-3 mb-4" style="background: var(--surface-sunken);">
                <p class="section-label mb-2">How to reach us</p>
                @if ($supportEmail)
                    <p class="mb-0">
                        <a href="mailto:{{ $supportEmail }}" class="fw-medium">{{ $supportEmail }}</a>
                    </p>
                @endif
                <a href="{{ route('legal.contact') }}" class="text-sm text-muted-foreground">
                    All contact details
                </a>
            </div>
        @elseif ($admins->isNotEmpty())
            <div class="rounded-3 p-3 text-start mb-4" style="background: var(--surface-sunken);">
                <p class="section-label mb-2">Who can unlock it</p>
                <ul class="list-unstyled mb-0">
                    @foreach ($admins as $admin)
                        <li class="text-sm">
                            <span class="fw-medium">{{ $admin->name }}</span>
                            <span class="text-muted-foreground">· {{ $admin->email }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($selfResolvable && auth()->user()->isStoreAdmin())
            <a href="{{ route('tenant.billing.index') }}" class="btn btn-primary mb-3 w-100">
                <i class="bi bi-credit-card me-1"></i> Go to billing
            </a>
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

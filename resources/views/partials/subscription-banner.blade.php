{{-- ST-Enforce (S1): trial countdown + grace-period warning. Tenant users only. --}}
@php
    $osmsSub = auth()->check() && ! auth()->user()->isSuperadmin()
        ? auth()->user()->tenant?->subscription
        : null;
    $osmsState = $osmsSub?->accessState();
@endphp

@if ($osmsSub && $osmsSub->status === 'trialing' && $osmsState === 'active')
    @php $osmsDaysLeft = $osmsSub->trialDaysLeft(); @endphp
    <div class="px-4 px-md-5 pt-4 no-print">
        <div class="alert alert-info alert-dismissible fade show d-flex align-items-center gap-2 mb-0 rounded-3" role="alert">
            <i class="bi bi-stars"></i>
            <div>
                You're on a free trial —
                <strong>{{ $osmsDaysLeft }} {{ \Illuminate\Support\Str::plural('day', $osmsDaysLeft) }}</strong> left.
                <a href="{{ route('tenant.billing.index') }}" class="alert-link">Subscribe now</a> to keep your store running.
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
@elseif ($osmsState === 'grace')
    <div class="px-4 px-md-5 pt-4 no-print">
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-0 rounded-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>
                Your last payment didn't go through and access is in a short grace period.
                <a href="{{ route('tenant.billing.index') }}" class="alert-link">Update your billing</a> to avoid interruption.
            </div>
        </div>
    </div>
@endif

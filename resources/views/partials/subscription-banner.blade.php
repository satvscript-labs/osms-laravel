{{-- ST-Enforce (S1): trial countdown + grace-period warning. Tenant users only. --}}
@php
    $osmsTenant = auth()->check() && ! auth()->user()->isSuperadmin() ? auth()->user()->tenant : null;

    // QA-07 — a closed or suspended STORE is explained by its lock page. A trial
    // banner above "This store has been closed" invited payment for a store we
    // had ended, and contradicted the page it sat on.
    $osmsSub = $osmsTenant && ! $osmsTenant->isBlocked()
        // AUD-02 — via the ACCOUNT, so a second branch still sees its banner.
        ? $osmsTenant->governingSubscription()
        : null;
    $osmsState = $osmsSub?->accessState();

    // Billed by hand: there is no checkout to send them to, so never say
    // "Subscribe now" — point at the billing page, which explains.
    // Asked only when a banner will show: it costs queries, on every page.
    $osmsSupervised = (($osmsSub?->status === 'trialing' && $osmsState === 'active') || $osmsState === 'grace')
        && (bool) $osmsTenant?->account?->isSupervised();
@endphp

@if ($osmsSub && $osmsSub->status === 'trialing' && $osmsState === 'active')
    @php $osmsDaysLeft = $osmsSub->trialDaysLeft(); @endphp
    <div class="px-4 px-md-5 pt-4 no-print">
        <div class="alert alert-info alert-dismissible fade show d-flex align-items-center gap-2 mb-0 rounded-3" role="alert">
            <i class="bi bi-stars"></i>
            <div>
                You're on a free trial —
                <strong>{{ $osmsDaysLeft }} {{ \Illuminate\Support\Str::plural('day', $osmsDaysLeft) }}</strong> left.
                @if ($osmsSupervised)
                    Our team handles your billing — <a href="{{ route('tenant.billing.index') }}" class="alert-link">see how to pay</a>.
                @else
                    <a href="{{ route('tenant.billing.index') }}" class="alert-link">Subscribe now</a> to keep your store running.
                @endif
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
                @if ($osmsSupervised)
                    Please <a href="{{ route('tenant.billing.index') }}" class="alert-link">get in touch with us</a> to avoid interruption.
                @else
                    <a href="{{ route('tenant.billing.index') }}" class="alert-link">Update your billing</a> to avoid interruption.
                @endif
            </div>
        </div>
    </div>
@endif

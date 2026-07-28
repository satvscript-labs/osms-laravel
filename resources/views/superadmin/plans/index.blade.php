@extends('layouts.app')
@section('title', 'Plans')

@section('content')
{{--
    P2 / REQ-4 — list prices, and who is on a bespoke one.
    Read-only here; editing arrives with the operator actions. Even read-only
    this answers a question that previously required reading config and the
    database together: "what do we charge, and who pays something else?"
--}}
<div class="p-4 p-md-5">

    <div class="mb-4">
        <p class="section-label mb-1">Platform</p>
        <h1 class="h3 fw-semibold font-display mb-1">Plans</h1>
        <p class="text-muted-foreground mb-0 text-md">
            List prices, and every customer on a hand-agreed rate.
        </p>
    </div>

    <div class="row g-3 mb-4 stagger">
        @forelse ($plans as $plan)
            <div class="col-md-6 col-lg-4">
                <div class="card card-lift border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h2 class="h6 fw-semibold font-display mb-0">{{ $plan->name }}</h2>
                            @if (! $plan->is_active)
                                <span class="meta-chip">inactive</span>
                            @endif
                        </div>
                        <p class="mb-1">
                            <span class="h4 fw-semibold font-display">₹ {{ number_format($plan->monthly_price, 0) }}</span>
                            <span class="text-muted-foreground text-sm">/month</span>
                        </p>
                        <p class="text-muted-foreground text-sm mb-3">
                            or ₹ {{ number_format($plan->yearly_price, 0) }}/year
                            @php $saving = ($plan->monthly_price * 12) - $plan->yearly_price; @endphp
                            @if ($saving > 0)
                                <span class="meta-chip ms-1">save ₹{{ number_format($saving, 0) }}</span>
                            @endif
                        </p>

                        @if (! empty($plan->features))
                            <ul class="list-unstyled text-sm text-muted-foreground mb-3">
                                @foreach ($plan->features as $feature)
                                    <li class="mb-1"><i class="bi bi-check2 me-1" style="color:var(--tone-green);"></i>{{ $feature }}</li>
                                @endforeach
                            </ul>
                        @endif

                        <p class="text-muted-foreground text-xs mb-0">
                            <span class="font-monospace">{{ $plan->code }}</span> ·
                            {{ $plan->subscriptions_count }} {{ Str::plural('subscriber', $plan->subscriptions_count) }}
                        </p>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
                    <h2 class="h5 fw-semibold font-display">No plans seeded</h2>
                    <p class="text-muted-foreground mb-0">
                        Run <code>php artisan db:seed --class=Database\Seeders\PlanSeeder</code>.
                    </p>
                </div>
            </div>
        @endforelse
    </div>

    <p class="section-label mb-2">On a bespoke price</p>
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        @forelse ($bespoke as $sub)
            <a href="{{ route('superadmin.accounts.show', $sub->account_id) }}" class="person-row">
                <span class="person-avatar">⚑</span>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="fw-semibold text-truncate">{{ $sub->account?->displayName() ?? '—' }}</span>
                        <span class="meta-chip">
                            ₹ {{ number_format($sub->negotiated_price, 0) }}/{{ $sub->interval === 'yearly' ? 'yr' : 'mo' }}
                        </span>
                        @if ($sub->plan)
                            <span class="text-faint text-sm">
                                list ₹ {{ number_format($sub->plan->priceFor($sub->interval ?? 'monthly'), 0) }}
                            </span>
                        @endif
                    </div>
                    @if ($sub->negotiated_reason)
                        <div class="text-muted-foreground text-sm mt-1">
                            <em>“{{ $sub->negotiated_reason }}”</em>
                            @if ($sub->negotiatedBy) — {{ $sub->negotiatedBy->name }} @endif
                            @if ($sub->negotiated_at) · {{ $sub->negotiated_at->format('d M Y') }} @endif
                        </div>
                    @endif
                </div>
                <i class="bi bi-chevron-right person-chevron"></i>
            </a>
        @empty
            <p class="text-muted-foreground text-center py-4 mb-0 text-sm">
                Nobody is on a negotiated price — everyone pays list.
            </p>
        @endforelse
    </div>
</div>
@endsection

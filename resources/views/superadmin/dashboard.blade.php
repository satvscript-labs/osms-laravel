@extends('layouts.app')
@section('title', 'Today')

@section('content')
<div class="p-4 p-md-5">

    <div class="mb-4">
        <p class="section-label mb-1">Platform</p>
        <h1 class="h3 fw-semibold font-display mb-1">Today</h1>
        <p class="text-muted-foreground mb-0 text-md">
            How the money is, how healthy the base is, and what needs you.
        </p>
    </div>

    {{-- A half-migrated deploy must be visible, never silent (P1 backfill). --}}
    @if ($unbackfilled > 0)
        <div class="card border-0 shadow-sm rounded-4 mb-4 animate-fade-up"
             style="border-left:3px solid var(--tone-amber) !important;">
            <div class="card-body p-4 d-flex align-items-start gap-3">
                <i class="bi bi-exclamation-triangle" style="color:var(--tone-amber);"></i>
                <div>
                    <p class="fw-semibold mb-1">{{ $unbackfilled }} {{ Str::plural('store', $unbackfilled) }} not yet linked to a customer</p>
                    <p class="text-muted-foreground text-sm mb-0">
                        Run <code>php artisan osms:backfill-accounts</code> — dry run first, then
                        <code>--commit</code>. Until then those stores have no billing identity.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- ---- 1. How is the money ---- --}}
    <p class="section-label mb-2">Money</p>
    <div class="row g-3 mb-4 stagger">
        <div class="col-6 col-lg-3">
            <x-metric-card label="{{ $stats['comped'] > 0 ? 'MRR · ' . $stats['comped'] . ' comped' : 'MRR' }}"
                           value="₹ {{ number_format($stats['mrr'], 0) }}" icon="bi-graph-up-arrow" tone="primary" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="ARR (run rate)" value="₹ {{ number_format($stats['arr'], 0) }}" icon="bi-calendar3" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Collected this month" value="₹ {{ number_format($stats['collected_month'], 0) }}" icon="bi-cash-coin" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Collected all time" value="₹ {{ number_format($stats['collected_all'], 0) }}" icon="bi-safe" />
        </div>
    </div>

    {{-- ---- 1b. Collection health (P6 / §8) ----
         Newly possible now that ONE ledger records every method. Labelled
         "expected", not "outstanding", because OSMS raises no invoices in
         advance — there is no receivable, only a price and a date. Saying so
         costs one line and stops the number being read as a debtors' book. --}}
    <div class="row g-3 mb-4 stagger">
        <div class="col-12 col-lg-6">
            <div class="card card-lift border-0 shadow-sm rounded-4 h-100"
                 @if ($collection['overdue_count'] > 0) style="border-left:3px solid var(--tone-amber) !important;" @endif>
                <div class="card-body p-4 d-flex align-items-start gap-3">
                    <span class="osms-stat-icon {{ $collection['overdue_count'] > 0 ? 'osms-stat-icon-amber' : 'osms-stat-icon-neutral' }} flex-shrink-0">
                        <i class="bi bi-exclamation-circle"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="section-label mb-1">Overdue</p>
                        <p class="h4 fw-semibold font-display mb-1">
                            ₹ {{ number_format($collection['overdue_amount'], 0) }}
                        </p>
                        <p class="text-muted-foreground text-sm mb-0">
                            @if ($collection['overdue_count'] === 0)
                                Nobody is behind on payment.
                            @else
                                from {{ $collection['overdue_count'] }}
                                {{ Str::plural('customer', $collection['overdue_count']) }} whose payment
                                has failed or not arrived.
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card card-lift border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4 d-flex align-items-start gap-3">
                    <span class="osms-stat-icon osms-stat-icon-blue flex-shrink-0">
                        <i class="bi bi-calendar-check"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="section-label mb-1">Expected in {{ $collection['horizon_days'] }} days</p>
                        <p class="h4 fw-semibold font-display mb-1">
                            ₹ {{ number_format($collection['due_soon_amount'], 0) }}
                        </p>
                        <p class="text-muted-foreground text-sm mb-0">
                            {{ $collection['due_soon_count'] }}
                            {{ Str::plural('renewal', $collection['due_soon_count']) }} at their current
                            price. Nothing is invoiced in advance, so this is what they would pay —
                            not a bill anyone has sent.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ---- 2. How healthy is the base ---- --}}
    <p class="section-label mb-2">Base</p>
    <div class="row g-3 mb-4 stagger">
        <div class="col-6 col-lg-2">
            <a href="{{ route('superadmin.accounts.index', ['filter' => 'all']) }}" class="text-decoration-none">
                <x-metric-card label="Customers" value="{{ $stats['accounts'] }}" icon="bi-person-vcard" tone="primary" />
            </a>
        </div>
        <div class="col-6 col-lg-2">
            <a href="{{ route('superadmin.stores.index') }}" class="text-decoration-none">
                <x-metric-card label="Stores" value="{{ $stats['stores'] }}" icon="bi-shop" />
            </a>
        </div>
        <div class="col-6 col-lg-2">
            <a href="{{ route('superadmin.accounts.index', ['filter' => 'paid']) }}" class="text-decoration-none">
                <x-metric-card label="Paying" value="{{ $stats['active'] }}" icon="bi-patch-check" />
            </a>
        </div>
        <div class="col-6 col-lg-2">
            <a href="{{ route('superadmin.accounts.index', ['filter' => 'trialing']) }}" class="text-decoration-none">
                <x-metric-card label="On trial" value="{{ $stats['trialing'] }}" icon="bi-hourglass-split" tone="amber" />
            </a>
        </div>
        <div class="col-6 col-lg-2">
            <a href="{{ route('superadmin.accounts.index', ['filter' => 'lapsed']) }}" class="text-decoration-none">
                <x-metric-card label="Past due" value="{{ $stats['past_due'] }}" icon="bi-exclamation-circle"
                               tone="{{ $stats['past_due'] > 0 ? 'amber' : 'default' }}" />
            </a>
        </div>
        <div class="col-6 col-lg-2">
            <x-metric-card label="Cancelled" value="{{ $stats['canceled'] }}" icon="bi-x-circle" />
        </div>
    </div>

    {{-- ---- 2b. Churn (P6 / §8) ----
         §8: "ship, but label honestly — at n=1 a single churn = 100%. Show
         counts, not percentages, until n ≥ 10." So this shows people and
         rupees, states the window, and admits what it cannot see. A metric
         that hides its own blind spot is worse than no metric. --}}
    <p class="section-label mb-2">Churn · last {{ $churn['window_days'] }} days</p>
    <div class="card border-0 shadow-sm rounded-4 mb-4 animate-fade-up">
        <div class="card-body p-4">
            <div class="row g-4">
                <div class="col-6 col-lg-3">
                    <p class="text-muted-foreground mb-1 text-xs">Customers lost</p>
                    <p class="h4 fw-semibold font-display mb-0">{{ $churn['logo'] }}</p>
                </div>
                <div class="col-6 col-lg-3">
                    <p class="text-muted-foreground mb-1 text-xs">Monthly revenue lost</p>
                    <p class="h4 fw-semibold font-display mb-0">₹ {{ number_format($churn['revenue'], 0) }}</p>
                </div>
                <div class="col-6 col-lg-3">
                    <p class="text-muted-foreground mb-1 text-xs">Trials that lapsed</p>
                    <p class="h4 fw-semibold font-display mb-0">{{ $churn['trials_lapsed'] }}</p>
                </div>
                <div class="col-6 col-lg-3">
                    <p class="text-muted-foreground mb-1 text-xs">Churn rate</p>
                    <p class="h4 fw-semibold font-display mb-0 text-faint">—</p>
                </div>
            </div>

            <div class="mt-3 pt-3" style="border-top:1px solid var(--osms-border);">
                <p class="text-muted-foreground text-sm mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    @unless ($churn['show_percentage'])
                        No rate is shown below {{ \App\Support\Metrics::PERCENTAGE_FLOOR }} paying
                        customers — with a handful of customers a single departure reads as a
                        catastrophic percentage and tells you nothing.
                    @else
                        A rate is now meaningful at this size and can be added.
                    @endunless
                    A lapsed trial is counted separately: it never paid, so losing it is a
                    different question.
                    @if ($churn['untracked'] > 0)
                        <br>
                        <i class="bi bi-exclamation-triangle me-1" style="color:var(--tone-amber);"></i>
                        {{ $churn['untracked'] }} earlier
                        {{ Str::plural('cancellation', $churn['untracked']) }}
                        {{ $churn['untracked'] === 1 ? 'predates' : 'predate' }} churn tracking and
                        {{ $churn['untracked'] === 1 ? 'is' : 'are' }} not counted above.
                    @endif
                </p>
            </div>
        </div>
    </div>

    {{-- ---- 3. What needs me today ---- --}}
    <div class="d-flex align-items-center justify-content-between mb-2">
        <p class="section-label mb-0">What needs you</p>
        @if ($worklist->isNotEmpty())
            <span class="osms-badge osms-badge-amber"><span class="osms-badge-dot"></span>{{ $worklist->count() }}</span>
        @endif
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden stagger">
        @forelse ($worklist as $row)
            <a href="{{ $row['url'] }}" class="person-row">
                <span class="person-avatar">
                    <i class="bi {{ $row['status'] === 'past_due' ? 'bi-exclamation-circle' : 'bi-hourglass-split' }}"></i>
                </span>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="fw-semibold text-truncate">{{ $row['account'] }}</span>
                        @include('superadmin.partials.status-pill', ['status' => $row['status'], 'access' => $row['access']])
                    </div>
                    <div class="text-muted-foreground text-sm mt-1">
                        {{ $row['why'] }}
                        @if ($row['ends'])
                            ·
                            @if ($row['days_left'] !== null && $row['days_left'] < 0)
                                <span style="color:var(--tone-red);">expired {{ abs($row['days_left']) }} {{ Str::plural('day', abs($row['days_left'])) }} ago</span>
                            @elseif ($row['days_left'] === 0)
                                <span style="color:var(--tone-amber);">today</span>
                            @else
                                in {{ $row['days_left'] }} {{ Str::plural('day', $row['days_left']) }}
                            @endif
                            <span class="text-faint">({{ $row['ends'] }})</span>
                        @endif
                    </div>
                </div>
                <i class="bi bi-chevron-right person-chevron"></i>
            </a>
        @empty
            <div class="text-center p-5">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle person-avatar mx-auto mb-3 person-avatar-lg"><i class="bi bi-check2"></i></span>
                <h2 class="h6 fw-semibold font-display mb-1">Nothing needs you today</h2>
                <p class="text-muted-foreground text-sm mb-0">
                    No overdue payments, and nothing expiring in the next two weeks.
                </p>
            </div>
        @endforelse
    </div>

    {{-- Recently joined --}}
    @if ($recent->isNotEmpty())
        <p class="section-label mt-4 mb-2">Recently joined</p>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden stagger">
            @foreach ($recent as $account)
                <a href="{{ route('superadmin.accounts.show', $account) }}" class="person-row">
                    <span class="person-avatar">{{ mb_strtoupper(mb_substr($account->displayName(), 0, 1)) }}</span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="fw-semibold text-truncate">{{ $account->displayName() }}</span>
                            @include('superadmin.partials.status-pill', [
                                'status' => $account->subscription?->status ?? 'none',
                                'access' => $account->subscription?->accessState(),
                            ])
                        </div>
                        <div class="text-muted-foreground text-sm mt-1">
                            {{ $account->stores_count }} {{ Str::plural('store', $account->stores_count) }}
                            · joined {{ $account->created_at?->format('d M Y') }}
                        </div>
                    </div>
                    <i class="bi bi-chevron-right person-chevron"></i>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection

@extends('layouts.app')
@section('title', $account->displayName())

@php
    $s = $subscription;
    $access = $s?->accessState() ?? 'locked';
    $daysLeft = $s?->current_period_end
        ? (int) now()->startOfDay()->diffInDays($s->current_period_end->startOfDay(), false)
        : null;
@endphp

@section('content')
{{--
    06 §9 — the Customer 360. One account, every commercial fact and every
    lever, on one screen.

    P3 added the actions. Each one lives in the shared <x-operator-modal>,
    previews before it commits, requires a reason when discretionary, and
    routes through SubscriptionLifecycle — the panel never mutates commercial
    state directly. Triggers are in the header dropdown, the Stores tab and
    the Ledger rows, so every matrix row is ≤3 clicks from here.
--}}
<div class="p-4 p-md-5">

    <a href="{{ route('superadmin.accounts.index') }}"
       class="text-decoration-none text-muted-foreground d-inline-flex align-items-center gap-1 mb-3 text-sm">
        <i class="bi bi-arrow-left"></i> All customers
    </a>

    {{-- P5 — a freshly issued password, shown exactly once. Top of the page,
         because if it scrolls past unnoticed it is gone. --}}
    @include('superadmin.partials.credential-card')

    {{-- ---- Header band ---- --}}
    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <span class="d-inline-flex align-items-center justify-content-center rounded-4 bg-primary text-white fw-semibold flex-shrink-0 order-1"
              style="width:3rem;height:3rem;font-size:1.25rem;">{{ mb_strtoupper(mb_substr($account->displayName(), 0, 1)) }}</span>

        {{-- P3 — primary action + the full lever set, inline and reachable
             without scrolling. Every matrix row is ≤3 clicks from here.
             Rendered after the identity block in DOM order so the heading is
             read first; `ms-lg-auto` pushes it right on wide screens and it
             wraps below on narrow ones. --}}
        @if ($subscription)
        <div class="ms-lg-auto d-flex gap-2 flex-shrink-0 order-3">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#m-payment">
                <i class="bi bi-cash-coin me-1"></i> Record payment
            </button>
            <div class="dropdown">
                <button class="btn btn-light" type="button" data-bs-toggle="dropdown" aria-expanded="false"
                        aria-label="More actions">
                    <i class="bi bi-three-dots"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="box-shadow: var(--shadow-overlay);">
                    <li><h6 class="dropdown-header">Money</h6></li>
                    <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-renew"><i class="bi bi-arrow-repeat me-2"></i>Renew now</button></li>
                    <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-price"><i class="bi bi-tag me-2"></i>Set negotiated price</button></li>
                    @if ($subscription->hasNegotiatedPrice())
                        <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-clear-price"><i class="bi bi-tag me-2"></i>Back to list price</button></li>
                    @endif
                    <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-interval"><i class="bi bi-calendar3 me-2"></i>Switch billing period</button></li>

                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Time</h6></li>
                    <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-extend"><i class="bi bi-hourglass-split me-2"></i>Extend</button></li>
                    <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-comp"><i class="bi bi-gift me-2"></i>Grant free access</button></li>

                    @if ($subscription->status === 'past_due')
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Overdue</h6></li>
                        <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-markpaid"><i class="bi bi-check2-circle me-2"></i>Mark as paid</button></li>
                        <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-waive"><i class="bi bi-hand-thumbs-up me-2"></i>Waive</button></li>
                    @endif

                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Access</h6></li>
                    @if ($subscription->override_kind === 'suspension')
                        <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-reactivate"><i class="bi bi-play-circle me-2"></i>Reactivate</button></li>
                    @else
                        <li><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#m-suspend"><i class="bi bi-pause-circle me-2"></i>Suspend access</button></li>
                    @endif
                    <li><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#m-cancel"><i class="bi bi-x-octagon me-2"></i>Cancel customer</button></li>
                    <li><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#m-expire"><i class="bi bi-exclamation-octagon me-2"></i>End access now</button></li>

                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Automation</h6></li>
                    <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-supervise">
                        <i class="bi bi-person-check me-2"></i>{{ $account->supervised ? 'Let them pay online' : 'Bill by hand' }}
                    </button></li>
                </ul>
            </div>
        </div>
        @endif

        <div class="min-w-0 flex-grow-1 order-2">
            <h1 class="h4 fw-semibold font-display mb-1 text-truncate">{{ $account->displayName() }}</h1>
            <div class="chip-rail">
                @include('superadmin.partials.status-pill', ['status' => $s?->status ?? 'none', 'access' => $access])
                @if ($s?->hasNegotiatedPrice())
                    <span class="meta-chip" title="On a hand-agreed price">⚑ bespoke</span>
                @endif
                @if ($s?->hasActiveOverride())
                    <span class="meta-chip text-capitalize"
                          title="Operator override in force — automation will not change this">
                        <i class="bi bi-hand-index-thumb"></i> {{ $s->override_kind }}
                    </span>
                @endif
                <span class="meta-chip"><i class="bi bi-shop"></i> {{ $account->stores->count() }} {{ Str::plural('store', $account->stores->count()) }}</span>
                @if ($account->isSupervised())
                    <span class="meta-chip" title="Self-serve checkout is closed — they pay through you">
                        <i class="bi bi-person-check"></i> billed by hand
                    </span>
                @endif
            </div>
            <p class="text-muted-foreground text-sm mt-2 mb-0">
                @if ($account->billing_email)
                    <i class="bi bi-envelope me-1"></i>{{ $account->billing_email }}
                @endif
                @if ($account->billing_phone)
                    <span class="ms-2"><i class="bi bi-telephone me-1"></i>{{ $account->billing_phone }}</span>
                @endif
            </p>
        </div>
    </div>

    {{-- ---- Fact strip ---- --}}
    <div class="row g-3 mb-4 stagger">
        <div class="col-6 col-lg-3">
            <x-metric-card label="Renews"
                           value="{{ $s?->current_period_end?->format('d M Y') ?? '—' }}"
                           icon="bi-calendar-check"
                           tone="{{ $daysLeft !== null && $daysLeft <= 14 ? 'amber' : 'default' }}" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Pays"
                           value="₹ {{ number_format($price['effective'] ?? 0, 0) }}{{ $s?->interval ? '/' . ($s->interval === 'yearly' ? 'yr' : 'mo') : '' }}"
                           icon="bi-tag" tone="primary" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Lifetime value" value="₹ {{ number_format($lifetime, 0) }}" icon="bi-safe" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="MRR" value="₹ {{ number_format($mrr, 0) }}" icon="bi-graph-up-arrow" />
        </div>
    </div>

    {{-- ---- Tabs ---- --}}
    <ul class="nav nav-pills gap-2 mb-3" role="tablist">
        @foreach ([
            'stores' => ['Stores', 'bi-shop'],
            'commercial' => ['Commercial', 'bi-cash-stack'],
            'ledger' => ['Ledger', 'bi-receipt'],
            'access' => ['Access', 'bi-people'],
            'notes' => ['Notes & audit', 'bi-journal-text'],
        ] as $key => [$label, $icon])
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $loop->first ? 'active' : '' }}" data-bs-toggle="pill"
                        data-bs-target="#tab-{{ $key }}" type="button" role="tab">
                    <i class="bi {{ $icon }} me-1"></i>{{ $label }}
                </button>
            </li>
        @endforeach
    </ul>

    <div class="tab-content">

        {{-- ===== Stores — the "units panel" (06 §9) =====
             One card per store. Deliberately NOT a full-row anchor with
             buttons hanging off it: nesting actions inside a link is
             ambiguous to click and reads as unfinished. The name is the
             link; the levers are their own controls, on their own line,
             separated by a hairline so the card has a real rhythm. --}}
        <div class="tab-pane fade show active" id="tab-stores" role="tabpanel">
            <div class="row g-3 stagger">
                @forelse ($account->stores as $store)
                    <div class="col-12 col-xl-6">
                        <div class="card card-lift border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-start gap-3">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0"
                                          style="width:2.5rem;height:2.5rem;background:var(--osms-primary-soft);color:var(--osms-primary);">
                                        <i class="bi bi-shop"></i>
                                    </span>
                                    <div class="min-w-0 flex-grow-1">
                                        <a href="{{ route('superadmin.stores.show', $store) }}"
                                           class="d-inline-flex align-items-center gap-1 text-decoration-none fw-semibold text-truncate">
                                            {{ $store->store_name }}
                                            <i class="bi bi-arrow-right-short"></i>
                                        </a>
                                        <div class="chip-rail mt-1">
                                            @if ($store->isClosed())
                                                <span class="osms-badge osms-badge-red"><span class="osms-badge-dot"></span>Closed</span>
                                            @elseif ($store->store_status !== 'active')
                                                <span class="osms-badge osms-badge-amber"><span class="osms-badge-dot"></span>Suspended</span>
                                            @else
                                                <span class="osms-badge osms-badge-green"><span class="osms-badge-dot"></span>Active</span>
                                            @endif
                                            @if ($store->is_billable)
                                                <span class="meta-chip" title="Counts toward what this customer is charged">Billed</span>
                                            @else
                                                <span class="meta-chip" title="Excluded from billing">Not billed</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                {{-- Facts, labelled. Bare icon+number was ambiguous —
                                     3,088 of what? --}}
                                <div class="row g-2 mt-3 text-center">
                                    @foreach ([
                                        ['Customers', number_format($store->customers_count)],
                                        ['Orders', number_format($store->orders_count)],
                                        ['Team', $store->users_count . '/' . $store->seatLimit()],
                                    ] as [$label, $value])
                                        <div class="col-4">
                                            <div class="rounded-3 py-2" style="background:var(--surface-sunken);">
                                                <div class="fw-semibold font-display">{{ $value }}</div>
                                                <div class="text-3xs text-faint text-uppercase" style="letter-spacing:.05em;">{{ $label }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                {{-- P5 — a closed store states its own deadline. The
                                     date is the reassurance: nothing is gone yet. --}}
                                @if ($store->isClosed())
                                    <div class="mt-3 p-3 rounded-3" style="background:var(--tone-red-bg);">
                                        <p class="fw-semibold mb-1 text-sm" style="color:var(--tone-red);">
                                            <i class="bi bi-archive me-1"></i>Closed
                                            {{ $store->closed_at?->format('d M Y') }}
                                        </p>
                                        <p class="text-muted-foreground text-sm mb-0">
                                            @if ($store->isPurgeable())
                                                The retention window has passed — this store's data can now be deleted.
                                            @else
                                                Everything is kept until <strong>{{ $store->purge_after?->format('d M Y') }}</strong>
                                                ({{ $store->daysUntilPurge() }} {{ Str::plural('day', $store->daysUntilPurge()) }}),
                                                and can be restored in full until then.
                                            @endif
                                            @if ($store->closure_reason) <br><em>“{{ $store->closure_reason }}”</em> @endif
                                        </p>
                                    </div>
                                @endif
                            </div>

                            <div class="px-4 py-3 d-flex flex-wrap gap-2"
                                 style="border-top:1px solid var(--osms-border);">
                                @if ($store->isClosed())
                                    <button type="button" class="btn btn-light btn-sm"
                                            data-bs-toggle="modal" data-bs-target="#m-reopen-{{ $store->id }}">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reopen
                                    </button>
                                    @if ($store->isPurgeable())
                                        <button type="button" class="btn btn-light btn-sm text-danger"
                                                data-bs-toggle="modal" data-bs-target="#m-purge-{{ $store->id }}">
                                            <i class="bi bi-trash3 me-1"></i>Delete permanently
                                        </button>
                                    @endif
                                @else
                                    <button type="button" class="btn btn-light btn-sm"
                                            data-bs-toggle="modal" data-bs-target="#m-store-{{ $store->id }}">
                                        <i class="bi {{ $store->store_status === 'suspended' ? 'bi-play-circle' : 'bi-pause-circle' }} me-1"></i>
                                        {{ $store->store_status === 'suspended' ? 'Reactivate' : 'Suspend' }}
                                    </button>
                                    <button type="button" class="btn btn-light btn-sm"
                                            data-bs-toggle="modal" data-bs-target="#m-bill-{{ $store->id }}">
                                        <i class="bi bi-receipt-cutoff me-1"></i>
                                        {{ $store->is_billable ? 'Exclude from billing' : 'Include in billing' }}
                                    </button>
                                    <button type="button" class="btn btn-light btn-sm text-danger ms-auto"
                                            data-bs-toggle="modal" data-bs-target="#m-close-{{ $store->id }}">
                                        <i class="bi bi-archive me-1"></i>Close
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle person-avatar mx-auto mb-3"
                                  style="width:3.25rem;height:3.25rem;font-size:1.2rem;"><i class="bi bi-shop"></i></span>
                            <h2 class="h6 fw-semibold font-display mb-1">No stores under this customer</h2>
                            <p class="text-muted-foreground text-sm mb-0">They are paying, but nothing is provisioned yet.</p>
                        </div>
                    </div>
                @endforelse

                {{-- P5 / matrix row 1 — a branch for someone who already pays.
                     It joins their existing clock rather than starting a second. --}}
                <div class="col-12 col-xl-6">
                    <a href="{{ route('superadmin.accounts.create', ['account' => $account->id]) }}"
                       class="card card-lift border-0 rounded-4 h-100 text-decoration-none d-flex align-items-center justify-content-center p-4 text-center"
                       style="border:1px dashed var(--osms-border-strong) !important;background:transparent;min-height:8rem;">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-3 mb-2"
                              style="width:2.5rem;height:2.5rem;background:var(--osms-primary-soft);color:var(--osms-primary);">
                            <i class="bi bi-plus-lg"></i>
                        </span>
                        <span class="fw-semibold">Add a store</span>
                        <span class="text-muted-foreground text-sm">Joins this customer's existing subscription</span>
                    </a>
                </div>
            </div>
        </div>

        {{-- ===== Commercial ===== --}}
        <div class="tab-pane fade" id="tab-commercial" role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-4">
                            <p class="section-label mb-3">Subscription</p>
                            <div class="row g-3 text-md">
                                @foreach ([
                                    'Status' => $s?->status ?? '—',
                                    'Access' => $access,
                                    'Plan' => $s?->plan?->name ?? ($s?->tier ?? '—'),
                                    'Interval' => $s?->interval ?? '—',
                                    'Period ends' => $s?->current_period_end?->format('d M Y') ?? '—',
                                    'Billable stores' => $account->billableStores()->count(),
                                ] as $label => $value)
                                    <div class="col-6 col-md-4">
                                        <p class="text-muted-foreground mb-0 text-xs">{{ $label }}</p>
                                        <p class="fw-medium mb-0 text-capitalize">{{ $value }}</p>
                                    </div>
                                @endforeach
                            </div>

                            @if ($s?->hasActiveOverride())
                                <div class="mt-4 p-3 rounded-3" style="background:var(--surface-sunken);">
                                    <p class="fw-semibold mb-1 text-sm">
                                        <i class="bi bi-hand-index-thumb me-1"></i>
                                        Operator override in force — <span class="text-capitalize">{{ $s->override_kind }}</span>
                                    </p>
                                    <p class="text-muted-foreground text-sm mb-0">
                                        Automation will not change this store's access
                                        @if ($s->override_until)
                                            until <strong>{{ $s->override_until->format('d M Y') }}</strong>.
                                        @else
                                            until an operator clears it.
                                        @endif
                                        @if ($s->override_reason) <br><em>“{{ $s->override_reason }}”</em> @endif
                                    </p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-4">
                            <p class="section-label mb-3">Price breakdown</p>
                            @if ($price)
                                {{-- Rendered from the SAME PriceResolver the charge will use,
                                     so a preview can never disagree with what is charged. --}}
                                @foreach ($price['steps'] as $step)
                                    <div class="d-flex justify-content-between py-2 border-bottom text-sm">
                                        <span class="text-muted-foreground">{{ $step['label'] }}</span>
                                        <span class="fw-medium">₹ {{ number_format($step['amount'], 2) }}</span>
                                    </div>
                                @endforeach
                                <div class="d-flex justify-content-between pt-3">
                                    <span class="fw-semibold">Effective</span>
                                    <span class="fw-semibold">₹ {{ number_format($price['effective'], 2) }}</span>
                                </div>
                                @if ($s?->negotiated_reason)
                                    <p class="text-muted-foreground text-sm mt-3 mb-0">
                                        ⚑ Bespoke price — <em>“{{ $s->negotiated_reason }}”</em>
                                        @if ($s->negotiatedBy) <br>set by {{ $s->negotiatedBy->name }} @endif
                                        @if ($s->negotiated_at) on {{ $s->negotiated_at->format('d M Y') }} @endif
                                    </p>
                                @else
                                    <p class="text-muted-foreground text-sm mt-3 mb-0">
                                        List price from
                                        {{ $price['list_source'] === 'plan' ? 'the plan' : 'config defaults' }}.
                                    </p>
                                @endif
                            @else
                                <p class="text-muted-foreground text-sm mb-0">No subscription.</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===== Ledger ===== --}}
        <div class="tab-pane fade" id="tab-ledger" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                @forelse ($ledger as $row)
                    <div class="person-row" style="cursor:default;">
                        <span class="person-avatar">
                            <i class="bi {{ $row->method === 'comp' ? 'bi-gift' : 'bi-cash' }}"></i>
                        </span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold {{ $row->isReversed() ? 'text-decoration-line-through text-faint' : '' }}">
                                    ₹ {{ number_format($row->amount, 2) }}
                                </span>
                                <span class="meta-chip">{{ $row->methodLabel() }}</span>
                                @if ($row->isReversed())
                                    <span class="osms-badge osms-badge-red"><span class="osms-badge-dot"></span>Reversed</span>
                                @endif
                            </div>
                            <div class="text-muted-foreground text-sm mt-1 d-flex align-items-center gap-2 flex-wrap">
                                @if ($row->receipt_no)<span class="font-monospace text-xs">{{ $row->receipt_no }}</span>@endif
                                @if ($row->period_start && $row->period_end)
                                    <span>{{ $row->period_start->format('d M y') }} → {{ $row->period_end->format('d M y') }}</span>
                                @endif
                                @if ($row->reference)<span class="text-faint">ref {{ $row->reference }}</span>@endif
                                @if ($row->reason)<em class="text-faint">“{{ $row->reason }}”</em>@endif
                            </div>
                        </div>
                        <div class="d-none d-md-block text-end" style="min-width:6rem;">
                            <div class="text-sm">{{ ($row->paid_at ?? $row->created_at)?->format('d M Y') }}</div>
                            <div class="text-3xs text-faint">{{ $row->recordedBy?->name ?? 'automatic' }}</div>
                        </div>
                        @unless ($row->isReversed())
                            <button type="button" class="btn btn-light btn-sm ms-2"
                                    data-bs-toggle="modal" data-bs-target="#m-rev-{{ $row->id }}"
                                    title="Reverse this payment" aria-label="Reverse this payment">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                        @endunless
                    </div>
                @empty
                    <p class="text-muted-foreground text-center py-4 mb-0 text-sm">
                        No payments recorded yet.
                    </p>
                @endforelse
            </div>
        </div>

        {{-- ===== Access (P5 — matrix row 14 + view-as) ===== --}}
        <div class="tab-pane fade" id="tab-access" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                @forelse ($account->stores as $store)
                    <div class="px-4 pt-4 pb-2 d-flex align-items-center justify-content-between gap-2">
                        <p class="section-label mb-0">{{ $store->store_name }}</p>
                        <span class="meta-chip">{{ $store->users->count() }}/{{ $store->seatLimit() }} seats</span>
                    </div>

                    @foreach ($store->users as $u)
                        <div class="person-row" style="cursor:default;">
                            <span class="person-avatar">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="fw-semibold text-truncate">{{ $u->name }}</span>
                                    <span class="meta-chip">{{ $u->role === 'store_admin' ? 'admin' : 'staff' }}</span>
                                    @if ($u->hasTwoFactorEnabled())
                                        <span class="meta-chip" title="Two-factor authentication is on"><i class="bi bi-shield-check"></i> 2FA</span>
                                    @endif
                                </div>
                                <div class="text-muted-foreground text-sm mt-1 text-truncate">{{ $u->email }}</div>
                            </div>

                            <div class="d-flex gap-2 flex-shrink-0">
                                {{-- Row 14. The secret is shown once, on return. --}}
                                <button type="button" class="btn btn-light btn-sm"
                                        data-bs-toggle="modal" data-bs-target="#m-cred-{{ $u->id }}"
                                        title="Issue a new password">
                                    <i class="bi bi-key"></i><span class="d-none d-lg-inline ms-1">New password</span>
                                </button>
                                @if ($store->store_status === 'active')
                                    <button type="button" class="btn btn-light btn-sm"
                                            data-bs-toggle="modal" data-bs-target="#m-view-{{ $u->id }}"
                                            title="See their screen, read-only">
                                        <i class="bi bi-eye"></i><span class="d-none d-lg-inline ms-1">View as</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @empty
                    <p class="text-muted-foreground text-center py-4 mb-0 text-sm">
                        Nobody can sign in — this customer has no stores yet.
                    </p>
                @endforelse
            </div>

            <p class="text-muted-foreground text-xs mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Viewing as a store is read-only and lasts
                {{ config('saas.impersonation_minutes', 30) }} minutes. Entering and leaving are
                both recorded against your name.
            </p>
        </div>

        {{-- ===== Notes & audit ===== --}}
        <div class="tab-pane fade" id="tab-notes" role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                                <p class="section-label mb-0">Internal notes</p>
                                <button type="button" class="btn btn-light btn-sm"
                                        data-bs-toggle="modal" data-bs-target="#m-notes">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </div>
                            <p class="text-muted-foreground text-sm mb-3">Private — never shown to the customer.</p>
                            <p class="mb-0 text-md" style="white-space:pre-line;">{{ $account->internal_notes ?: '—' }}</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-4">
                            <p class="section-label mb-3">Activity</p>
                            @forelse ($auditLogs as $log)
                                <div class="py-2 border-bottom text-sm">
                                    <p class="mb-0 fw-medium">{{ $log->description }}</p>
                                    <p class="mb-0 text-muted-foreground text-xs">
                                        {{ $log->admin_email ?? 'system' }} · {{ $log->created_at?->diffForHumans() }}
                                    </p>
                                </div>
                            @empty
                                <p class="text-muted-foreground text-center py-3 mb-0 text-sm">No activity yet.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- P3 — every action's overlay, rendered once. Triggers live above. --}}
@include('superadmin.accounts.partials.actions')
@endsection

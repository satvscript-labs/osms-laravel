@extends('layouts.app')
@section('title', $customer->name)

@section('content')
<div class="p-4 p-md-5">
    <a href="{{ route('tenant.customers.index') }}"
       class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3" style="font-size:var(--text-sm);">
        <i class="bi bi-chevron-left"></i> Back to customers
    </a>

    {{-- Customer header. `position-relative z-2` (Bootstrap utilities): .glass's
         backdrop-filter creates its own stacking context, which otherwise traps
         the ⋯ dropdown menu underneath the "History" section that follows in the
         DOM — this lifts the whole header (dropdown included) above it. --}}
    <div class="glass card-lift rounded-4 p-4 mb-4 d-flex flex-column flex-lg-row gap-3 align-items-lg-center justify-content-between position-relative z-2">
        <div class="d-flex align-items-start gap-3 min-w-0">
            <span class="d-inline-flex align-items-center justify-content-center rounded-4 bg-primary text-white flex-shrink-0"
                  style="width:3.25rem;height:3.25rem;"><i class="bi bi-person fs-4"></i></span>
            <div class="min-w-0">
                <h1 class="h3 fw-semibold font-display mb-1 text-truncate">{{ $customer->name }}</h1>
                <div class="d-flex flex-wrap align-items-center gap-2 gap-md-3 text-muted-foreground text-sm">
                    @if ($customer->phone)
                        <span class="text-nowrap"><i class="bi bi-telephone me-1"></i>{{ $customer->phone }}</span>
                    @else
                        {{-- SHARE-01 — a number is optional; say so plainly and make it fixable. --}}
                        <a href="{{ route('tenant.customers.edit', $customer) }}"
                           class="meta-chip meta-chip-warn" title="No phone number on file — click to add one">
                            <i class="bi bi-telephone-x"></i> No number
                        </a>
                    @endif
                    @if ($customer->age)<span class="text-nowrap"><i class="bi bi-calendar3 me-1"></i>{{ $customer->age }} yrs</span>@endif
                    @if ($customer->gender)<span class="text-capitalize">{{ $customer->gender }}</span>@endif
                    <span class="text-xs text-nowrap">Added {{ $customer->created_at->format('d M Y') }}</span>

                    {{-- SHARE-01 — a household number is shared, so anything sent to
                         it may be seen by a relative. Jumps to the panel below. --}}
                    @if ($household->isNotEmpty())
                        <a href="#household" class="shared-chip text-decoration-none"
                           title="This number is shared with {{ $household->count() }} other {{ Str::plural('person', $household->count()) }}">
                            <i class="bi bi-people-fill"></i>
                            Shared with {{ $household->count() }}
                        </a>
                    @endif

                    {{-- PRIV-02 — a minor is legally relevant (guardian consent, no marketing). --}}
                    @if ($customer->isMinor())
                        <span class="meta-chip meta-chip-warn" title="Under 18 — guardian consent applies; excluded from birthday marketing">
                            <i class="bi bi-person-exclamation"></i> Minor
                        </span>
                    @endif

                    {{-- PRIV-01 — surface only the EXCEPTION. Consent on file is the normal
                         state and needs no badge; a missing one is actionable, so it links
                         straight to the form that fixes it. --}}
                    @unless ($customer->data_consent_at)
                        <a href="{{ route('tenant.customers.edit', $customer) }}"
                           class="meta-chip meta-chip-warn" title="Data consent is not recorded — click to add it">
                            <i class="bi bi-shield-exclamation"></i> No consent
                        </a>
                    @endunless
                </div>
            </div>
        </div>

        {{-- Actions. Quick-contact affordances are icon-only circles (secondary), so the
             two labelled buttons stay on one line instead of wrapping raggedly. --}}
        <div class="d-flex flex-wrap flex-lg-nowrap align-items-center gap-2 flex-shrink-0">
            @if ($customer->whatsappUrl())
                {{-- SHARE-01 — on a household number the message reaches a handset a
                     relative may be holding. Say so in the tooltip rather than
                     letting staff find out afterwards. --}}
                <a href="{{ $customer->whatsappUrl() }}" target="_blank" rel="noopener"
                   class="wa-pill contact-pill-round"
                   title="{{ $household->isNotEmpty()
                        ? 'Shared number — ' . $household->count() . ' other ' . Str::plural('person', $household->count()) . ' may see this message'
                        : 'Message on WhatsApp' }}"
                   aria-label="Message {{ $customer->name }} on WhatsApp">
                    <i class="bi bi-whatsapp" aria-hidden="true"></i> <span>WhatsApp</span>
                </a>
            @endif
            @if ($customer->telHref())
                <a href="{{ $customer->telHref() }}"
                   class="call-pill contact-pill-round" title="Call {{ $customer->phone }}"
                   aria-label="Call {{ $customer->name }}">
                    <i class="bi bi-telephone-fill" aria-hidden="true"></i> <span>Call</span>
                </a>
            @endif
            <a href="{{ route('tenant.eye-records.create', $customer) }}" class="btn btn-outline-primary text-nowrap">
                <i class="bi bi-plus-lg me-1"></i> Eye record
            </a>
            <a href="{{ safe_route('tenant.orders.create', ['customer' => $customer->id]) }}" class="btn btn-primary text-nowrap">
                <i class="bi bi-cart-plus me-1"></i> New order
            </a>
            <div class="dropdown">
                <button class="btn btn-light" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions">
                    <i class="bi bi-three-dots"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow rounded-3 border-0" style="box-shadow: var(--shadow-overlay);">
                    <li>
                        <a class="dropdown-item d-flex align-items-center gap-2" href="{{ route('tenant.customers.edit', $customer) }}">
                            <i class="bi bi-pencil"></i> Edit profile
                        </a>
                    </li>

                    {{-- SHARE-01 — merging duplicates. Frozen as "Coming soon" in
                         production: it re-points orders, payments and prescriptions
                         and then discards a profile, which needs its own undo story
                         before a shop can be handed it. Same freeze pattern as
                         WhatsApp Automated. --}}
                    <li>
                        @if (config('customers.merge_enabled'))
                            <a class="dropdown-item d-flex align-items-center gap-2"
                               href="{{ safe_route('tenant.customers.merge', $customer) }}">
                                <i class="bi bi-union"></i> Merge with another profile
                            </a>
                        @else
                            <span class="dropdown-item d-flex align-items-center gap-2 disabled"
                                  aria-disabled="true" tabindex="-1"
                                  title="Merging duplicate profiles is coming soon"
                                  style="cursor:not-allowed; color: var(--osms-faint);">
                                <i class="bi bi-union"></i>
                                Merge with another profile
                                <span class="soon-badge ms-auto">Soon</span>
                            </span>
                        @endif
                    </li>

                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('tenant.customers.destroy', $customer) }}" class="m-0">
                            @csrf
                            @method('DELETE')
                            <button type="button" class="dropdown-item d-flex align-items-center gap-2 text-danger"
                                    data-confirm="Archive {{ $customer->name }}? The record is recoverable from the archive for 30 days."
                                    data-confirm-title="Archive customer"
                                    data-confirm-label="Archive">
                                <i class="bi bi-archive"></i> Archive customer
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    {{-- SHARE-01 — the household. A phone number is a handset a family shares, so
         these are the other people reachable on it. Records are entirely separate;
         this panel exists so staff know who else a call or message could reach, and
         so a record filed against the wrong relative is easy to spot. --}}
    @if ($household->isNotEmpty())
        <div id="household" class="household mb-4 animate-fade-up">
            <div class="household-head">
                <i class="bi bi-people-fill"></i>
                Also on {{ $customer->phone }}
            </div>
            <div class="household-list stagger">
                @foreach ($household as $member)
                    <a href="{{ route('tenant.customers.show', $member) }}"
                       class="household-member text-decoration-none">
                        <span class="person-avatar">
                            {{ Str::of($member->name)->explode(' ')->take(2)->map(fn ($w) => Str::substr($w, 0, 1))->implode('') }}
                        </span>
                        <span class="min-w-0">
                            <span class="d-block fw-medium text-truncate">{{ $member->name }}</span>
                            <span class="d-flex align-items-center gap-2 mt-1">
                                @if ($member->eye_records_count > 0)
                                    <span class="osms-badge osms-badge-blue">
                                        <span class="osms-badge-dot"></span>
                                        {{ $member->eye_records_count }} {{ Str::plural('test', $member->eye_records_count) }}
                                    </span>
                                @endif
                                @if ($member->age)
                                    <span class="meta-chip"><i class="bi bi-person"></i> {{ $member->age }} yrs</span>
                                @endif
                                <span class="text-faint text-3xs">Added {{ $member->created_at->format('d M Y') }}</span>
                            </span>
                        </span>
                        <span class="household-member-pick">Open <i class="bi bi-arrow-right"></i></span>
                    </a>
                @endforeach
            </div>
            <div class="household-confirmed">
                <i class="bi bi-info-circle mt-1"></i>
                <span>
                    Separate profiles with separate prescription histories — they only share the
                    number. Anything sent to it may be seen by any of them.
                </span>
            </div>
        </div>
    @endif

    <h2 class="h5 fw-semibold font-display mb-3">History</h2>

    @if ($timeline->isEmpty())
        <div class="border border-2 border-dashed rounded-4 bg-white bg-opacity-50 text-center p-5">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary mb-3"
                  style="width:3rem;height:3rem;"><i class="bi bi-clipboard fs-4"></i></span>
            <p class="fw-medium mb-1">No history yet</p>
            <p class="text-muted-foreground mb-0">Add an eye record to begin this customer's timeline.</p>
        </div>
    @else
        <div class="d-flex flex-column gap-3">
            @foreach ($timeline as $item)
                @if ($item['kind'] === 'rx')
                    <x-eye-record-card :record="$item['data']" />
                @else
                    @php $o = $item['data']; @endphp
                    <a href="{{ safe_route('tenant.orders.show', $o->id) }}"
                       class="card card-lift border-0 shadow-sm rounded-4 text-decoration-none text-reset">
                        <div class="card-body p-4 d-flex align-items-start justify-content-between gap-3">
                            <div class="d-flex align-items-start gap-2">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary"
                                      style="width:2.25rem;height:2.25rem;"><i class="bi bi-cart3"></i></span>
                                <div>
                                    <p class="mb-0 fw-medium">Order ₹ {{ number_format($o->total_amount, 2) }}</p>
                                    <p class="mb-0 text-muted-foreground" style="font-size:var(--text-xs);">
                                        Advance ₹ {{ number_format($o->advance_paid, 2) }} ·
                                        Balance ₹ {{ number_format($o->balance_due, 2) }} ·
                                        {{ $o->created_at->format('d M Y') }}
                                    </p>
                                </div>
                            </div>
                            <span class="badge text-capitalize {{ $o->status === 'delivered' ? 'text-bg-light' : ($o->status === 'ready_for_pickup' ? 'text-bg-primary' : 'text-bg-secondary') }}">
                                {{ str_replace('_', ' ', $o->status) }}
                            </span>
                        </div>
                    </a>
                @endif
            @endforeach
        </div>
    @endif
</div>
@endsection

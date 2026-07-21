@extends('layouts.app')
@section('title', $customer->name)

@section('content')
<div class="p-4 p-md-5">
    <a href="{{ route('tenant.customers.index') }}"
       class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3" style="font-size:.8rem;">
        <i class="bi bi-chevron-left"></i> Back to customers
    </a>

    {{-- Customer header. `position-relative z-2` (Bootstrap utilities): .glass's
         backdrop-filter creates its own stacking context, which otherwise traps
         the ⋯ dropdown menu underneath the "History" section that follows in the
         DOM — this lifts the whole header (dropdown included) above it. --}}
    <div class="glass card-lift rounded-4 p-4 mb-4 d-flex flex-column flex-md-row gap-3 align-items-md-end justify-content-between position-relative z-2">
        <div class="d-flex align-items-start gap-3">
            <span class="d-inline-flex align-items-center justify-content-center rounded-4 bg-primary text-white"
                  style="width:3.25rem;height:3.25rem;"><i class="bi bi-person fs-4"></i></span>
            <div>
                <h1 class="h3 fw-semibold font-display mb-1">{{ $customer->name }}</h1>
                <div class="d-flex flex-wrap gap-3 text-muted-foreground" style="font-size:.85rem;">
                    <span><i class="bi bi-telephone me-1"></i>{{ $customer->phone }}</span>
                    @if ($customer->age)<span><i class="bi bi-calendar3 me-1"></i>{{ $customer->age }} yrs</span>@endif
                    @if ($customer->gender)<span class="text-capitalize">{{ $customer->gender }}</span>@endif
                    <span style="font-size:.78rem;">Added {{ $customer->created_at->format('d M Y') }}</span>
                    {{-- PRIV-01 — consent status at a glance. --}}
                    @if ($customer->data_consent_at)
                        <span class="osms-badge osms-badge-green"><span class="osms-badge-dot"></span> Consent on file</span>
                    @else
                        <span class="osms-badge osms-badge-amber"><span class="osms-badge-dot"></span> Consent not recorded</span>
                    @endif
                    @if ($customer->whatsapp_opt_in)
                        <span class="osms-badge osms-badge-blue"><span class="osms-badge-dot"></span> WhatsApp opt-in</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            {{-- Quick contact (FT-WhatsApp req 2) — open a chat / dial from your own device. --}}
            @if ($customer->whatsappUrl())
                <a href="{{ $customer->whatsappUrl() }}" target="_blank" rel="noopener"
                   class="wa-pill" aria-label="Message {{ $customer->name }} on WhatsApp">
                    <i class="bi bi-whatsapp"></i> <span>WhatsApp</span>
                </a>
            @endif
            @if ($customer->telHref())
                <a href="{{ $customer->telHref() }}"
                   class="call-pill" aria-label="Call {{ $customer->name }}">
                    <i class="bi bi-telephone-fill"></i> <span>Call</span>
                </a>
            @endif
            <a href="{{ route('tenant.eye-records.create', $customer) }}" class="btn btn-outline-primary">
                <i class="bi bi-plus-lg me-1"></i> New eye record
            </a>
            <a href="{{ safe_route('tenant.orders.create', ['customer' => $customer->id]) }}" class="btn btn-primary">
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
                                    <p class="mb-0 text-muted-foreground" style="font-size:.78rem;">
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

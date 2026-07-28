@extends('layouts.app')
@section('title', $store->store_name)

@section('content')
{{--
    P2 — one store: OPERATIONAL facts only. Commercial levers live on the
    Customer 360, because money belongs to the payer, not the shop. The
    breadcrumb makes that relationship explicit rather than implied.
--}}
<div class="p-4 p-md-5">

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 text-sm">
        <a href="{{ route('superadmin.stores.index') }}"
           class="text-decoration-none text-muted-foreground d-inline-flex align-items-center gap-1">
            <i class="bi bi-arrow-left"></i> All stores
        </a>
        @if ($account)
            <span class="text-faint">·</span>
            <a href="{{ route('superadmin.accounts.show', $account) }}"
               class="text-decoration-none text-muted-foreground">
                <i class="bi bi-person-vcard me-1"></i>{{ $account->displayName() }}
            </a>
        @endif
    </div>

    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <span class="d-inline-flex align-items-center justify-content-center rounded-4 bg-primary text-white flex-shrink-0"
              style="width:3rem;height:3rem;font-size:1.25rem;"><i class="bi bi-shop"></i></span>
        <div class="min-w-0">
            <h1 class="h4 fw-semibold font-display mb-1 text-truncate">{{ $store->store_name }}</h1>
            <div class="chip-rail">
                @if ($store->store_status !== 'active')
                    <span class="osms-badge osms-badge-red"><span class="osms-badge-dot"></span>Suspended</span>
                @else
                    <span class="osms-badge osms-badge-green"><span class="osms-badge-dot"></span>Active</span>
                @endif
                @unless ($store->is_billable)
                    <span class="meta-chip" title="Excluded from billing">not billed</span>
                @endunless
                @if ($store->hasGst())
                    <span class="meta-chip">GST {{ $store->effectiveGstRate() }}%</span>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4 stagger">
        <div class="col-6 col-lg-3">
            <x-metric-card label="Customers" value="{{ number_format($store->customers_count) }}" icon="bi-people" tone="primary" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Orders" value="{{ number_format($store->orders_count) }}" icon="bi-cart3" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Inventory items" value="{{ number_format($store->inventory_count) }}" icon="bi-box-seam" />
        </div>
        <div class="col-6 col-lg-3">
            <x-metric-card label="Team" value="{{ $store->users->count() }}/{{ $store->seatLimit() }}" icon="bi-person-badge" />
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Store details</p>
                    <div class="row g-3 text-md">
                        <div class="col-md-6">
                            <p class="text-muted-foreground mb-0 text-xs">Customer (billing)</p>
                            <p class="fw-medium mb-0">
                                @if ($account)
                                    <a href="{{ route('superadmin.accounts.show', $account) }}"
                                       class="text-decoration-none">{{ $account->displayName() }}</a>
                                @else
                                    <span class="text-faint">Not linked — run the backfill</span>
                                @endif
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted-foreground mb-0 text-xs">GST / Tax ID</p>
                            <p class="fw-medium mb-0">{{ $store->tax_id ?: '—' }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted-foreground mb-0 text-xs">Address</p>
                            <p class="fw-medium mb-0">{{ $store->address ?: '—' }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted-foreground mb-0 text-xs">Created</p>
                            <p class="fw-medium mb-0">{{ $store->created_at?->format('d M Y') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Activity on this store</p>
                    @forelse ($auditLogs as $log)
                        <div class="py-2 border-bottom text-sm">
                            <p class="mb-0 fw-medium">{{ $log->description }}</p>
                            <p class="mb-0 text-muted-foreground text-xs">
                                {{ $log->admin_email ?? 'system' }} · {{ $log->created_at?->diffForHumans() }}
                            </p>
                        </div>
                    @empty
                        <p class="text-muted-foreground text-center py-3 mb-0 text-sm">No admin actions yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Team ({{ $store->users->count() }})</p>
                    @foreach ($store->users as $u)
                        <div class="d-flex align-items-center gap-2 py-1 text-sm">
                            <div class="flex-grow-1 min-w-0">
                                <p class="mb-0 fw-medium text-truncate">{{ $u->name }}</p>
                                <p class="mb-0 text-muted-foreground text-xs text-truncate">{{ $u->email }}</p>
                            </div>
                            <span class="meta-chip">{{ $u->role === 'store_admin' ? 'admin' : 'staff' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Integrations</p>
                    <div class="d-flex align-items-center justify-content-between text-sm">
                        <span><i class="bi bi-whatsapp me-1"></i> WhatsApp</span>
                        <span class="meta-chip text-capitalize">
                            {{ $store->whatsappConfig?->mode ?? 'off' }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

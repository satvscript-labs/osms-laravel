@php
    $user = auth()->user();
    $tenant = $user?->tenant;
    $isSuper = $user?->isSuperadmin();

    $tenantLinks = [
        ['route' => 'tenant.dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'match' => 'tenant.dashboard'],
        ['route' => 'tenant.customers.index', 'label' => 'Customers', 'icon' => 'bi-people', 'match' => 'tenant.customers.*'],
        ['route' => 'tenant.inventory.index', 'label' => 'Inventory', 'icon' => 'bi-box-seam', 'match' => 'tenant.inventory.*'],
        ['route' => 'tenant.orders.index', 'label' => 'Orders', 'icon' => 'bi-cart3', 'match' => 'tenant.orders.*'],
        ['route' => 'tenant.analytics.index', 'label' => 'Analytics', 'icon' => 'bi-bar-chart', 'match' => 'tenant.analytics.*'],
    ];

    // Team management + activity log are admin-only.
    if ($user?->isStoreAdmin()) {
        $tenantLinks[] = ['route' => 'tenant.staff.index', 'label' => 'Team', 'icon' => 'bi-person-badge', 'match' => 'tenant.staff.*'];
        $tenantLinks[] = ['route' => 'tenant.activity.index', 'label' => 'Activity', 'icon' => 'bi-clock-history', 'match' => 'tenant.activity.*'];
    }

    // Settings now lives in the unified hub reachable via the gear in the footer
    // (profile.edit) — no separate sidebar entry.
    $superLinks = [
        ['route' => 'superadmin.dashboard', 'label' => 'Overview', 'icon' => 'bi-speedometer2', 'match' => 'superadmin.dashboard'],
        ['route' => 'superadmin.tenants.index', 'label' => 'Stores', 'icon' => 'bi-shop', 'match' => 'superadmin.tenants.*'],
        ['route' => 'superadmin.audit.index', 'label' => 'Audit log', 'icon' => 'bi-clock-history', 'match' => 'superadmin.audit.*'],
    ];
    $links = $isSuper ? $superLinks : $tenantLinks;
@endphp

<aside class="app-sidebar {{ ($mobile ?? false) ? 'offcanvas-body p-0 d-flex flex-column' : 'd-none d-md-flex' }}">
    {{-- Store header --}}
    <div class="d-flex align-items-center gap-2 px-3 py-3">
        @if ($tenant?->logo_url)
            <img src="{{ $tenant->logo_url }}" alt="{{ $tenant->store_name }}"
                 class="rounded-3 object-fit-cover border" style="width:2.25rem;height:2.25rem;">
        @else
            <span class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-3"
                  style="width:2.25rem;height:2.25rem;">
                <i class="bi bi-shop"></i>
            </span>
        @endif
        <div class="flex-grow-1 min-w-0">
            <p class="mb-0 fw-semibold font-display text-truncate" style="font-size:.9rem;">
                {{ $tenant?->store_name ?? ($isSuper ? 'OSMS Platform' : 'Your Store') }}
            </p>
            <p class="mb-0 text-muted-foreground text-capitalize" style="font-size:.72rem;">
                {{ str_replace('_', ' ', $user?->role) }}
            </p>
        </div>
    </div>

    {{-- Global search trigger (tenant only) --}}
    @unless ($isSuper)
        <div class="px-3 pb-2">
            <button type="button"
                    class="btn btn-sm w-100 d-flex align-items-center gap-2 bg-white border text-muted-foreground rounded-3 px-2 py-2"
                    data-bs-toggle="modal" data-bs-target="#globalSearchModal" style="font-size:.82rem;">
                <i class="bi bi-search"></i>
                <span>Search…</span>
                <kbd class="kbd-chip ms-auto">Ctrl K</kbd>
            </button>
        </div>
    @endunless

    {{-- Nav --}}
    <nav class="flex-grow-1 px-2">
        @foreach ($links as $link)
            <a href="{{ safe_route($link['route']) }}"
               class="sidebar-link mb-1 {{ request()->routeIs($link['match']) ? 'active' : '' }}">
                <i class="bi {{ $link['icon'] }}"></i>
                {{ $link['label'] }}
            </a>
        @endforeach
    </nav>

    {{-- Subscription (its own section, above the account) --}}
    @if ($user?->isStoreAdmin())
        <div class="border-top px-2 py-2">
            <a href="{{ safe_route('tenant.billing.index') }}"
               class="sidebar-link {{ request()->routeIs('tenant.billing.*') ? 'active' : '' }}">
                <i class="bi bi-credit-card"></i>
                Subscription
            </a>
        </div>
    @endif

    {{-- User footer --}}
    <div class="border-top px-2 py-2">
        <div class="d-flex align-items-center gap-2 px-2 py-1">
            <div class="flex-grow-1 min-w-0">
                <p class="mb-0 fw-medium text-truncate" style="font-size:.85rem;">{{ $user?->name ?? 'User' }}</p>
            </div>
            <a href="{{ route('profile.edit') }}"
               class="btn btn-sm {{ request()->routeIs('profile.edit') ? 'btn-primary' : 'btn-light' }}" title="Settings">
                <i class="bi bi-gear"></i>
            </a>
            <form method="POST" action="{{ route('logout') }}" class="m-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-light" title="Log out">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </form>
        </div>
    </div>
</aside>

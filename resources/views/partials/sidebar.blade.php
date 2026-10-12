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
    // P2 — the account-first control terminal. "Customers" = accounts (who pays);
    // "Stores" = tenants (which shops are live). Two surfaces, two questions.
    // The legacy store screens stay reachable by URL but are deliberately NOT
    // listed here — never two nav entries for the same job (playbook §4).
    $superLinks = [
        ['route' => 'superadmin.dashboard', 'label' => 'Today', 'icon' => 'bi-sunrise', 'match' => 'superadmin.dashboard'],
        ['route' => 'superadmin.accounts.index', 'label' => 'Customers', 'icon' => 'bi-person-vcard', 'match' => 'superadmin.accounts.*'],
        ['route' => 'superadmin.stores.index', 'label' => 'Stores', 'icon' => 'bi-shop', 'match' => 'superadmin.stores.*'],
        ['route' => 'superadmin.billing.index', 'label' => 'Billing', 'icon' => 'bi-receipt', 'match' => 'superadmin.billing.*'],
        ['route' => 'superadmin.plans.index', 'label' => 'Plans', 'icon' => 'bi-tags', 'match' => 'superadmin.plans.*'],
        ['route' => 'superadmin.audit.index', 'label' => 'Audit', 'icon' => 'bi-clock-history', 'match' => 'superadmin.audit.*'],
        ['route' => 'superadmin.platform.index', 'label' => 'Platform', 'icon' => 'bi-sliders', 'match' => 'superadmin.platform.*'],
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
            <p class="mb-0 fw-semibold font-display text-truncate" style="font-size:var(--text-md);">
                {{ $tenant?->store_name ?? ($isSuper ? 'OSMS Platform' : 'Your Store') }}
            </p>
            <p class="mb-0 text-muted-foreground text-capitalize" style="font-size:var(--text-xs);">
                {{ str_replace('_', ' ', $user?->role) }}
            </p>
        </div>
    </div>

    {{-- Global search trigger (tenant only) --}}
    @unless ($isSuper)
        <div class="px-3 pb-2">
            <button type="button"
                    class="btn btn-sm w-100 d-flex align-items-center gap-2 bg-white border text-muted-foreground rounded-3 px-2 py-2"
                    data-bs-toggle="modal" data-bs-target="#globalSearchModal" style="font-size:var(--text-sm);">
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
    @php
        $displayName = $user?->name ?? 'User';
        // Initials from the first and last word, so "Sahaj Optical (Local Test Owner)"
        // still reduces to a clean two-letter mark.
        $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $displayName)) ?: 'U');
        $initials = mb_strtoupper(mb_substr($words[0] ?? 'U', 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    @endphp
    <div class="border-top px-2 py-2">
        <div class="sidebar-account">
            <span class="sidebar-account-avatar">{{ $initials }}</span>
            {{-- min-w-0 is what allows text-truncate to engage inside a flex row. --}}
            <div class="flex-grow-1 min-w-0">
                <p class="mb-0 fw-medium text-truncate" style="font-size:var(--text-sm);"
                   title="{{ $displayName }}">{{ $displayName }}</p>
                @if ($user?->email)
                    <p class="mb-0 text-muted-foreground text-truncate" style="font-size:var(--text-3xs);"
                       title="{{ $user->email }}">{{ $user->email }}</p>
                @endif
            </div>
            <div class="sidebar-account-actions">
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
    </div>
</aside>

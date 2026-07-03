@extends('layouts.app')
@section('title', 'Orders')

@section('content')
<div class="p-4 p-md-5">
    {{-- Header --}}
    <div class="d-flex flex-column flex-md-row gap-3 align-items-md-end justify-content-between mb-4">
        <div>
            <p class="section-label mb-1">Workspace</p>
            <h1 class="h3 fw-semibold font-display mb-1">Orders</h1>
            <p class="text-muted-foreground mb-0 text-md">
                Find, filter, and advance orders through the lab workflow.
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            {{-- View toggle (iOS segmented control) --}}
            <div class="segmented" role="group" aria-label="View mode">
                <a href="{{ route('tenant.orders.index') }}"
                   class="segmented-item {{ $view === 'table' ? 'active' : '' }}">
                    <i class="bi bi-table"></i> Table
                </a>
                <a href="{{ route('tenant.orders.index', ['view' => 'kanban']) }}"
                   class="segmented-item {{ $view === 'kanban' ? 'active' : '' }}">
                    <i class="bi bi-kanban"></i> Board
                </a>
            </div>
            <a href="{{ route('tenant.orders.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> New order
            </a>
        </div>
    </div>

    {{-- KPI stat cards (clickable → filter the table) --}}
    <div class="row g-3 mb-4 stagger">
        <div class="col-6 col-lg-3">
            <a href="{{ route('tenant.orders.index') }}"
               class="osms-stat card border-0 shadow-sm rounded-4 h-100 text-reset text-decoration-none {{ $view==='table' && empty($status) && empty($payment) ? 'osms-stat-active' : '' }}">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="osms-stat-icon osms-stat-icon-neutral"><i class="bi bi-receipt"></i></span>
                    <div>
                        <div class="osms-stat-value font-display">{{ number_format($stats['total']) }}</div>
                        <div class="osms-stat-label">Total orders</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('tenant.orders.index', ['status' => 'pending']) }}"
               class="osms-stat card border-0 shadow-sm rounded-4 h-100 text-reset text-decoration-none {{ ($status ?? '')==='pending' ? 'osms-stat-active' : '' }}">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="osms-stat-icon osms-stat-icon-amber"><i class="bi bi-clock-history"></i></span>
                    <div>
                        <div class="osms-stat-value font-display">{{ number_format($stats['pending']) }}</div>
                        <div class="osms-stat-label">In lab</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('tenant.orders.index', ['status' => 'ready_for_pickup']) }}"
               class="osms-stat card border-0 shadow-sm rounded-4 h-100 text-reset text-decoration-none {{ ($status ?? '')==='ready_for_pickup' ? 'osms-stat-active' : '' }}">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="osms-stat-icon osms-stat-icon-blue"><i class="bi bi-bag-check"></i></span>
                    <div>
                        <div class="osms-stat-value font-display">{{ number_format($stats['ready']) }}</div>
                        <div class="osms-stat-label">Ready for pickup</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('tenant.orders.index', ['payment' => 'outstanding']) }}"
               class="osms-stat card border-0 shadow-sm rounded-4 h-100 text-reset text-decoration-none {{ ($payment ?? '')==='outstanding' ? 'osms-stat-active' : '' }}">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="osms-stat-icon osms-stat-icon-red"><i class="bi bi-cash-coin"></i></span>
                    <div>
                        <div class="osms-stat-value font-display">₹{{ number_format($stats['outstanding'], 0) }}</div>
                        <div class="osms-stat-label">Outstanding</div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    @if ($view === 'kanban')
        @include('tenant.orders.partials.kanban')
    @else
        @include('tenant.orders.partials.table')
    @endif
</div>

@push('scripts')
<script>
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const STATUS_URL = (id) => `{{ url('tenant/orders') }}/${id}/status`;

    function updateStatus(id, status) {
        return fetch(STATUS_URL(id), {
            method: 'PATCH',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ status }),
        });
    }
    window.updateStatus = updateStatus;

    // Bind inline quick-advance. Called on load and again after the live table
    // swaps its rows in (new buttons need binding). Idempotent per-button.
    function bindAdvanceButtons(root) {
        (root || document).querySelectorAll('.advance-btn').forEach((btn) => {
            if (btn._advBound) return;
            btn._advBound = true;
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                btn.disabled = true;
                btn.querySelector('.spinner-border')?.classList.remove('d-none');
                // Reload after a status change so the KPI stats + row placement stay
                // correct; the page-enter fade keeps it from feeling like a snap.
                updateStatus(btn.dataset.id, btn.dataset.next).then(() => window.location.reload());
            });
        });
    }
    window.bindAdvanceButtons = bindAdvanceButtons;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bindAdvanceButtons(document));
    } else {
        bindAdvanceButtons(document);
    }

    // Keyboard: "/" focuses the orders search (table view only).
    document.addEventListener('keydown', (e) => {
        const searchInput = document.getElementById('orderSearch');
        if (searchInput && e.key === '/' && document.activeElement !== searchInput
            && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
            e.preventDefault();
            searchInput.focus();
        }
    });

    // Kanban drag-and-drop (board view only). Wait for the deferred Vite ESM bundle
    // to define window.Sortable before instantiating.
    function initKanbanDnd() {
        if (!window.Sortable) return;
        document.querySelectorAll('.kanban-column').forEach((col) => {
            new Sortable(col, {
                group: 'orders', animation: 180, ghostClass: 'sortable-ghost',
                onEnd(evt) {
                    const newStatus = evt.to.dataset.status;
                    const id = evt.item.dataset.id;
                    if (newStatus !== evt.from.dataset.status) {
                        updateStatus(id, newStatus).then(() => window.location.reload());
                    }
                },
            });
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initKanbanDnd);
    } else {
        initKanbanDnd();
    }
</script>
@endpush
@endsection

@extends('layouts.app')
@section('title', 'Stores')

@section('content')
<div class="p-4 p-md-5" x-data="storeDirectory({ stores: @js($tenants) })">
    <div class="d-flex flex-column flex-md-row gap-3 align-items-md-end justify-content-between mb-4">
        <div>
            <p class="section-label mb-1">Platform</p>
            <h1 class="h3 fw-semibold font-display mb-1">Stores</h1>
            <p class="text-muted-foreground mb-0" style="font-size:.9rem;">Every store on OSMS. Click one to manage its subscription.</p>
        </div>
    </div>

    {{-- Controls --}}
    <div class="d-flex flex-column flex-md-row gap-2 mb-3">
        <div class="position-relative flex-grow-1">
            <i class="bi bi-search position-absolute text-faint" style="left:.9rem;top:50%;transform:translateY(-50%);"></i>
            <input type="search" x-model="query" placeholder="Search store name or owner email…"
                   class="form-control ps-5" style="max-width:none;">
        </div>
        <select x-model="status" class="form-select" style="max-width:220px;">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="trialing">Trialing</option>
            <option value="past_due">Past due</option>
            <option value="canceled">Canceled</option>
        </select>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0 osms-orders-table">
                <thead class="text-muted-foreground" style="font-size:.76rem;">
                    <tr>
                        <th class="ps-4">Store</th>
                        <th>Status</th>
                        <th>Plan</th>
                        <th class="text-end">MRR</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">Users</th>
                        <th class="text-end pe-4">Joined</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="t in filtered" :key="t.id">
                        <tr @click="window.location = t.url" style="cursor:pointer;">
                            <td class="ps-4">
                                <div class="fw-medium d-flex align-items-center gap-2">
                                    <span x-text="t.name"></span>
                                    <span class="badge text-bg-light border" x-show="t.manual" title="Manually managed">manual</span>
                                </div>
                                <div class="text-muted-foreground" style="font-size:.75rem;" x-text="t.owner_email || '—'"></div>
                            </td>
                            <td>
                                <span class="badge"
                                      :class="{
                                        'text-bg-success': t.status === 'active' || t.status === 'trialing',
                                        'text-bg-warning': t.status === 'past_due',
                                        'text-bg-secondary': t.status === 'canceled' || t.status === 'none',
                                      }"
                                      x-text="t.status"></span>
                                <span class="text-muted-foreground ms-1" style="font-size:.72rem;"
                                      x-show="t.status === 'trialing' && t.trial_days_left !== null"
                                      x-text="'· ' + t.trial_days_left + 'd left'"></span>
                            </td>
                            <td class="text-capitalize">
                                <span x-text="t.interval ? t.interval : '—'"></span>
                            </td>
                            <td class="text-end" x-text="t.mrr > 0 ? '₹ ' + t.mrr.toLocaleString('en-IN') : '—'"></td>
                            <td class="text-end" x-text="'₹ ' + (t.revenue_total || 0).toLocaleString('en-IN')"></td>
                            <td class="text-end" x-text="t.users"></td>
                            <td class="text-end pe-4" x-text="t.created"></td>
                        </tr>
                    </template>
                    <tr x-show="filtered.length === 0">
                        <td colspan="7" class="text-center text-muted-foreground py-5">No stores match your filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted-foreground mt-2" style="font-size:.78rem;">
        <span x-text="filtered.length"></span> of <span x-text="stores.length"></span> stores
    </p>
</div>

@push('scripts')
<script>
    function storeDirectory(config) {
        return {
            stores: config.stores || [],
            query: '',
            status: '',
            get filtered() {
                const q = this.query.trim().toLowerCase();
                return this.stores.filter(t => {
                    if (this.status && t.status !== this.status) return false;
                    if (!q) return true;
                    return (t.name || '').toLowerCase().includes(q)
                        || (t.owner_email || '').toLowerCase().includes(q);
                });
            },
        };
    }
</script>
@endpush
@endsection

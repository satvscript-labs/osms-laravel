@extends('layouts.app')
@section('title', 'Archived inventory')

@section('content')
{{-- UX-04 — live archive search (see partials/trash-list-script for why this swaps
     a server-rendered partial rather than client-rendering JSON). --}}
<div class="p-4 p-md-5"
     x-data="trashList({ endpoint: @js(route('tenant.inventory.trash')), query: @js($search ?? '') })">
    <a href="{{ route('tenant.inventory.index') }}"
       class="d-inline-flex align-items-center gap-1 text-muted-foreground text-decoration-none mb-3 text-sm">
        <i class="bi bi-chevron-left"></i> Back to inventory
    </a>

    {{-- Header --}}
    <div class="d-flex flex-column flex-md-row gap-3 align-items-md-end justify-content-between mb-4">
        <div>
            <p class="section-label mb-1">Inventory</p>
            <h1 class="h3 fw-semibold font-display mb-1">Archive</h1>
            <p class="text-muted-foreground mb-0 text-md">
                Archived items are recoverable for 30 days, then permanently removed.
            </p>
        </div>
    </div>

    {{-- Live search --}}
    <div class="input-group flex-nowrap mb-3" style="max-width:30rem;">
        <span class="input-group-text bg-white border-end-0">
            <i class="bi bi-search text-muted-foreground"></i>
        </span>
        <input type="search" x-model="query" @input.debounce.220ms="refresh()"
               class="form-control border-start-0"
               placeholder="Search by SKU, barcode, brand or model…" autocomplete="off"
               aria-label="Search archived inventory">
        <span class="input-group-text bg-white border-start-0" x-show="loading" x-cloak>
            <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
        </span>
        <button type="button" class="btn btn-light border-start-0" x-show="query && !loading" x-cloak
                @click="query=''; refresh()" aria-label="Clear search">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    {{-- Skeleton while fetching --}}
    <div x-show="loading" x-cloak class="card border-0 shadow-sm rounded-4 p-4">
        <template x-for="i in 4" :key="i">
            <div class="skeleton mb-2" style="height:2.25rem; border-radius: var(--radius);"></div>
        </template>
    </div>

    <div x-ref="rows" x-show="!loading">
        @include('tenant.inventory.partials.trash-rows')
    </div>
</div>
@endsection

@push('scripts')
    @include('partials.trash-list-script')
@endpush

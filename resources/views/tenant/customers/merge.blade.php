{{--
    SHARE-01 — merge a duplicate profile into this one.

    BASIC LAYOUT ONLY. The feature is frozen as "Coming soon" in production
    (config/customers.php → merge_enabled) and this page is unreachable there.
    It exists so the shape of the eventual UI is settled and the route/gate are
    real and tested, not so it can be used yet.

    Still to design before this ships:
      • which profile survives, and how conflicting fields are resolved;
      • an undo path — this moves orders, payments and prescriptions and then
        discards a profile, and a wrong merge is worse than a duplicate;
      • an activity-log entry recording who merged what into what.
--}}
@extends('layouts.app')
@section('title', 'Merge profiles')

@section('content')
    <div class="p-4 p-md-5" style="max-width:52rem;">
        <div class="d-flex align-items-center gap-2 mb-1">
            <p class="section-label mb-0">Customers</p>
            <span class="soon-badge">Coming soon</span>
        </div>
        <h1 class="h3 fw-semibold font-display mb-1">Merge into {{ $customer->name }}</h1>
        <p class="text-muted-foreground mb-4">
            Combine a duplicate profile into this one, keeping every order and prescription.
        </p>

        <div class="alert alert-warning d-flex align-items-start gap-2 rounded-3 mb-4" role="note">
            <i class="bi bi-cone-striped mt-1"></i>
            <div>
                <strong>This is a preview.</strong> Merging moves orders, payments and prescriptions
                onto one profile and then discards the other — it cannot be undone yet, so it is
                switched off until an undo path exists.
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-semibold font-display mb-3">Possible duplicates</h2>

                @if ($candidates->isEmpty())
                    <p class="text-muted-foreground mb-0">
                        No likely duplicates found — nobody else shares this number or a similar name.
                    </p>
                @else
                    <div class="household">
                        <div class="household-list stagger">
                            @foreach ($candidates as $candidate)
                                <div class="household-member" style="cursor:default;">
                                    <span class="person-avatar">
                                        {{ Str::of($candidate->name)->explode(' ')->take(2)->map(fn ($w) => Str::substr($w, 0, 1))->implode('') }}
                                    </span>
                                    <span class="min-w-0">
                                        <span class="d-block fw-medium text-truncate">{{ $candidate->name }}</span>
                                        <span class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                            <span class="text-muted-foreground text-2xs">
                                                {{ $candidate->phone ?? 'No number' }}
                                            </span>
                                            @if ($candidate->eye_records_count)
                                                <span class="osms-badge osms-badge-blue">
                                                    <span class="osms-badge-dot"></span>
                                                    {{ $candidate->eye_records_count }} {{ Str::plural('test', $candidate->eye_records_count) }}
                                                </span>
                                            @endif
                                            @if ($candidate->orders_count)
                                                <span class="meta-chip">
                                                    <i class="bi bi-cart3"></i> {{ $candidate->orders_count }} {{ Str::plural('order', $candidate->orders_count) }}
                                                </span>
                                            @endif
                                            @if ($candidate->phone && $candidate->phone === $customer->phone)
                                                <span class="shared-chip"><i class="bi bi-people-fill"></i> Same number</span>
                                            @endif
                                        </span>
                                    </span>
                                    <button type="button" class="btn btn-light btn-sm ms-auto" disabled>
                                        Merge <span class="soon-badge ms-1">Soon</span>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <p class="text-muted-foreground text-sm mb-0 mt-3">
                        <i class="bi bi-info-circle me-1"></i>
                        People who simply share a household number are <strong>not</strong> duplicates —
                        check the name and history before merging anyone.
                    </p>
                @endif
            </div>
        </div>

        <div class="mt-3">
            <a href="{{ route('tenant.customers.show', $customer) }}" class="btn btn-light">
                <i class="bi bi-arrow-left me-1"></i> Back to {{ $customer->name }}
            </a>
        </div>
    </div>
@endsection

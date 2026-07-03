@extends('layouts.app')
@section('title', 'Team')

@section('content')
<div class="p-4 p-md-5">
    <div class="mb-4 d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <p class="section-label mb-1">Account</p>
            <h1 class="h3 fw-semibold font-display mb-1">Team</h1>
            <p class="text-muted-foreground mb-0" style="font-size:.9rem;">Invite and manage the people in your store.</p>
        </div>
        <div class="text-md-end">
            <p class="section-label mb-1">Seats used</p>
            <span class="h5 fw-semibold font-display mb-0">{{ $tenant->seatsUsed() }} / {{ $tenant->seatLimit() }}</span>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success py-2 px-3 small rounded-3">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger py-2 px-3 small rounded-3">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger py-2 px-3 small rounded-3">{{ $errors->first() }}</div>
    @endif

    <div class="row g-4">
        {{-- Members --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Members</p>
                    <div class="d-flex flex-column gap-2">
                        @foreach ($members as $member)
                            <div class="d-flex align-items-center gap-3 p-2 rounded-3 border">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-semibold"
                                      style="width:2.25rem;height:2.25rem;">{{ strtoupper(substr($member->name, 0, 1)) }}</span>
                                <div class="flex-grow-1 min-w-0">
                                    <p class="mb-0 fw-medium text-truncate">{{ $member->name }}
                                        @if ($member->id === auth()->id())<span class="text-muted-foreground">(you)</span>@endif
                                    </p>
                                    <p class="mb-0 text-muted-foreground text-truncate" style="font-size:.8rem;">{{ $member->email }}</p>
                                </div>
                                <span class="badge {{ $member->role === 'store_admin' ? 'text-bg-primary' : 'text-bg-secondary' }}">
                                    {{ $member->role === 'store_admin' ? 'Admin' : 'Staff' }}
                                </span>

                                <div class="dropdown">
                                    <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="bi bi-three-dots"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <form method="POST" action="{{ route('tenant.staff.role', $member) }}">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="role" value="{{ $member->role === 'store_admin' ? 'staff' : 'store_admin' }}">
                                                <button type="submit" class="dropdown-item">
                                                    {{ $member->role === 'store_admin' ? 'Change to Staff' : 'Make Admin' }}
                                                </button>
                                            </form>
                                        </li>
                                        @if ($member->id !== auth()->id())
                                            <li>
                                                <form method="POST" action="{{ route('tenant.staff.remove', $member) }}">
                                                    @csrf @method('DELETE')
                                                    <button type="button" class="dropdown-item text-danger"
                                                            data-confirm="Remove {{ $member->name }} from your store? They will lose access immediately."
                                                            data-confirm-title="Remove member?"
                                                            data-confirm-label="Remove">Remove</button>
                                                </form>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Pending invitations --}}
                    @if ($invitations->isNotEmpty())
                        <p class="section-label mt-4 mb-3">Pending invitations</p>
                        <div class="d-flex flex-column gap-2">
                            @foreach ($invitations as $invite)
                                <div class="d-flex align-items-center gap-3 p-2 rounded-3 border border-dashed">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-body-secondary text-muted-foreground"
                                          style="width:2.25rem;height:2.25rem;"><i class="bi bi-envelope"></i></span>
                                    <div class="flex-grow-1 min-w-0">
                                        <p class="mb-0 fw-medium text-truncate">{{ $invite->email }}</p>
                                        <p class="mb-0 text-muted-foreground" style="font-size:.8rem;">
                                            {{ $invite->role === 'store_admin' ? 'Admin' : 'Staff' }} · expires {{ $invite->expires_at?->format('d M Y') }}
                                        </p>
                                    </div>
                                    <form method="POST" action="{{ route('tenant.staff.invitations.resend', $invite) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-light" title="Resend"><i class="bi bi-arrow-repeat"></i></button>
                                    </form>
                                    <form method="POST" action="{{ route('tenant.staff.invitations.revoke', $invite) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-light text-danger" title="Revoke"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Invite --}}
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Invite someone</p>
                    @if ($tenant->canAddSeat())
                        <form method="POST" action="{{ route('tenant.staff.invite') }}" class="d-flex flex-column gap-3">
                            @csrf
                            <div>
                                <label class="form-label small fw-medium">Email address</label>
                                <input type="email" name="email" class="form-control" placeholder="name@example.com" required>
                            </div>
                            <div>
                                <label class="form-label small fw-medium">Role</label>
                                <select name="role" class="form-select">
                                    <option value="staff">Staff — day-to-day POS &amp; orders</option>
                                    <option value="store_admin">Admin — full access incl. billing</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Send invitation</button>
                        </form>
                    @else
                        <div class="alert alert-warning py-2 px-3 small rounded-3 mb-0">
                            You've reached your limit of {{ $tenant->seatLimit() }} users. Remove a member to invite someone new.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Data export (DPDP portability) --}}
            <div class="card border-0 shadow-sm rounded-4 mt-4">
                <div class="card-body p-4">
                    <p class="section-label mb-2">Your data</p>
                    <p class="text-muted-foreground mb-3" style="font-size:.85rem;">Download your store's records anytime.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('tenant.customers.export') }}" class="btn btn-sm btn-light"><i class="bi bi-download me-1"></i> Customers</a>
                        <a href="{{ route('tenant.inventory.export') }}" class="btn btn-sm btn-light"><i class="bi bi-download me-1"></i> Inventory</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

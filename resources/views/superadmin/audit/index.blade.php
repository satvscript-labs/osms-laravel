@extends('layouts.app')
@section('title', 'Audit log')

@section('content')
<div class="p-4 p-md-5">
    <div class="mb-4">
        <p class="section-label mb-1">Platform</p>
        <h1 class="h3 fw-semibold font-display mb-1">Audit log</h1>
        <p class="text-muted-foreground mb-0" style="font-size:var(--text-md);">Every superadmin action, newest first. Append-only.</p>
    </div>

    <div class="card border-0 shadow-sm rounded-4 stagger">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="text-muted-foreground" style="font-size:var(--text-xs);">
                    <tr>
                        <th class="ps-4">When</th>
                        <th>Admin</th>
                        <th>Action</th>
                        <th>Store</th>
                        <th class="pe-4">IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="ps-4" style="font-size:var(--text-sm);">
                                <div class="fw-medium">{{ $log->created_at?->format('d M Y, H:i') }}</div>
                                <div class="text-muted-foreground" style="font-size:var(--text-xs);">{{ $log->created_at?->diffForHumans() }}</div>
                            </td>
                            <td style="font-size:var(--text-sm);">{{ $log->admin_email ?? '—' }}</td>
                            <td style="font-size:var(--text-sm);">
                                <div class="fw-medium">{{ $log->description }}</div>
                                <code class="text-muted-foreground" style="font-size:var(--text-xs);">{{ $log->action }}</code>
                            </td>
                            <td style="font-size:var(--text-sm);">
                                @if ($log->tenant)
                                    <a href="{{ route('superadmin.stores.show', $log->tenant) }}" class="text-decoration-none">{{ $log->tenant->store_name }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="pe-4 text-muted-foreground" style="font-size:var(--text-xs);">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted-foreground py-5">No actions logged yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $logs->links() }}
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title', 'Activity log')

@section('content')
<div class="p-4 p-md-5">
    <div class="mb-4">
        <p class="section-label mb-1">Store</p>
        <h1 class="h3 fw-semibold font-display mb-1">Activity log</h1>
        <p class="text-muted-foreground mb-0 text-md">Who changed or deleted records, newest first. Append-only.</p>
    </div>

    <div class="card border-0 shadow-sm rounded-4 stagger">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="text-muted-foreground text-xs">
                    <tr>
                        <th class="ps-4">When</th>
                        <th>Who</th>
                        <th>Action</th>
                        <th class="pe-4 d-none d-md-table-cell">IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="ps-4 text-sm">
                                <div class="fw-medium">{{ $log->created_at?->format('d M Y, H:i') }}</div>
                                <div class="text-muted-foreground text-2xs">{{ $log->created_at?->diffForHumans() }}</div>
                            </td>
                            <td class="text-sm">{{ $log->user_name ?? ($log->user?->name ?? '—') }}</td>
                            <td class="text-sm">
                                <div class="fw-medium">{{ $log->description }}</div>
                                <code class="text-muted-foreground text-2xs">{{ $log->action }}</code>
                            </td>
                            <td class="pe-4 text-muted-foreground text-xs d-none d-md-table-cell">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted-foreground py-5">No activity logged yet.</td></tr>
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

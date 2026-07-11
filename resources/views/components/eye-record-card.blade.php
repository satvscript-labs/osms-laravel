@props(['record'])

@php
    $cols = [
        ['key' => 'sph', 'label' => 'SPH'],
        ['key' => 'cyl', 'label' => 'CYL'],
        ['key' => 'axis', 'label' => 'Axis'],
        ['key' => 'add', 'label' => 'ADD'],
        ['key' => 'va', 'label' => 'VA'],
        ['key' => 'dv', 'label' => 'D.V.'],
        ['key' => 'nv', 'label' => 'N.V.'],
    ];
    $val = function ($eye, $key) use ($record) {
        $v = $record->{"{$eye}_{$key}"};
        return ($v === null || $v === '') ? '—' : $v;
    };
@endphp

<div class="card card-lift border-0 shadow-sm rounded-4">
    <div class="card-body p-4">
        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
            <div class="d-flex align-items-start gap-2">
                <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary"
                      style="width:2.25rem;height:2.25rem;"><i class="bi bi-eye"></i></span>
                <div>
                    <p class="mb-0 fw-medium">Eye record</p>
                    <p class="mb-0 text-muted-foreground" style="font-size:.75rem;">
                        {{ $record->created_at->format('d M Y') }}@if ($record->checked_by) · Examined by {{ $record->checked_by }}@endif
                    </p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                @if (! is_null($record->pd))
                    <div class="border rounded-3 bg-body px-3 py-1 text-end">
                        <p class="mb-0 text-muted-foreground text-uppercase" style="font-size:.62rem;letter-spacing:.05em;">PD</p>
                        <p class="mb-0 fw-semibold font-display">{{ $record->pd }} mm</p>
                    </div>
                @endif

                <div class="dropdown no-print">
                    <button class="btn btn-light btn-sm rounded-3 d-inline-flex align-items-center justify-content-center"
                            style="width:2rem;height:2rem;" type="button" data-bs-toggle="dropdown" aria-expanded="false"
                            aria-label="Record actions">
                        <i class="bi bi-three-dots"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow rounded-3 border-0" style="box-shadow: var(--shadow-overlay);">
                        <li>
                            <a class="dropdown-item d-flex align-items-center gap-2" href="{{ route('tenant.eye-records.edit', $record) }}">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('tenant.eye-records.destroy', $record) }}" class="m-0">
                                @csrf
                                @method('DELETE')
                                <button type="button" class="dropdown-item d-flex align-items-center gap-2 text-danger"
                                        data-confirm="Delete the {{ $record->created_at->format('d M Y') }} eye prescription? This action cannot be undone."
                                        data-confirm-title="Delete eye record"
                                        data-confirm-label="Delete record">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.85rem;">
                <thead class="text-muted-foreground text-uppercase" style="font-size:.68rem;letter-spacing:.04em;">
                    <tr>
                        <th style="width:3rem;"></th>
                        @foreach ($cols as $c)<th>{{ $c['label'] }}</th>@endforeach
                    </tr>
                </thead>
                <tbody class="font-monospace">
                    <tr>
                        <td class="fw-semibold text-muted-foreground">OD</td>
                        @foreach ($cols as $c)<td>{{ $val('od', $c['key']) }}</td>@endforeach
                    </tr>
                    <tr>
                        <td class="fw-semibold text-muted-foreground">OS</td>
                        @foreach ($cols as $c)<td>{{ $val('os', $c['key']) }}</td>@endforeach
                    </tr>
                </tbody>
            </table>
        </div>

        @if ($record->notes)
            <p class="mt-3 mb-0 bg-light rounded-3 px-3 py-2 text-muted-foreground" style="font-size:.85rem;">
                <span class="fw-medium text-dark">Notes: </span>{{ $record->notes }}
            </p>
        @endif
    </div>
</div>

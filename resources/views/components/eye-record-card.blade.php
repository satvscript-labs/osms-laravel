@props(['record'])

@php
    $val = function ($eye, $key) use ($record) {
        $v = $record->{"{$eye}_{$key}"};
        if ($v === null || $v === '') return '—';
        // Plano is written bare — "+0.00" reads as a typo. Matches the entry form.
        if (in_array($key, ['sph', 'cyl', 'nv', 'add']) && is_numeric($v)) {
            return (float) $v > 0 ? sprintf('+%.2f', (float) $v) : sprintf('%.2f', (float) $v);
        }
        // An axis is degrees, and 0 means "not recorded" (1..180 is the range).
        if ($key === 'axis' && is_numeric($v)) {
            return (int) $v === 0 ? '—' : (int) $v . '°';
        }
        return $v;
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
                    <p class="mb-0 text-muted-foreground" style="font-size:var(--text-xs);">
                        {{ $record->created_at->format('d M Y') }}@if ($record->checked_by) · Examined by {{ $record->checked_by }}@endif
                    </p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                @if (! is_null($record->pd))
                    <div class="border rounded-3 bg-body px-3 py-1 text-end">
                        <p class="mb-0 text-muted-foreground text-uppercase" style="font-size:var(--text-3xs);letter-spacing:.05em;">PD</p>
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

        {{-- Prescription table (Form layout) --}}
        <div class="table-responsive">
            <table class="table align-middle mb-0" style="font-size:var(--text-md);">
                <thead class="text-muted-foreground text-uppercase bg-light" style="font-size:var(--text-2xs);letter-spacing:.03em;">
                    <tr>
                        <th class="ps-4" style="width:8rem;">Measurement</th>
                        <th colspan="4" class="text-center py-3">RIGHT EYE (OD)</th>
                        <th colspan="4" class="text-center py-3 border-start">LEFT EYE (OS)</th>
                        <th class="pe-4"></th>
                    </tr>
                    <tr style="border-top:1px solid var(--osms-border);">
                        <th class="ps-4 py-2"></th>
                        <th class="text-center" style="width:6.5rem;">SPH</th>
                        <th class="text-center" style="width:6.5rem;">CYL</th>
                        <th class="text-center" style="width:6.5rem;">AXIS</th>
                        <th class="text-center" style="width:6.5rem;">V/S</th>
                        <th class="text-center" style="width:6.5rem;">SPH</th>
                        <th class="text-center" style="width:6.5rem;">CYL</th>
                        <th class="text-center" style="width:6.5rem;">AXIS</th>
                        <th class="text-center" style="width:6.5rem;">V/S</th>
                        <th class="pe-4"></th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Distance Vision (D.V.) --}}
                    <tr>
                        <td class="ps-4 fw-medium text-muted-foreground" style="font-size:var(--text-sm);">D.V.</td>
                        <td class="text-center font-monospace">{{ $val('od', 'sph') }}</td>
                        <td class="text-center font-monospace">{{ $val('od', 'cyl') }}</td>
                        <td class="text-center font-monospace">{{ $val('od', 'axis') }}</td>
                        <td class="text-center font-monospace">{{ $val('od', 'va') }}</td>
                        <td class="text-center font-monospace border-start">{{ $val('os', 'sph') }}</td>
                        <td class="text-center font-monospace">{{ $val('os', 'cyl') }}</td>
                        <td class="text-center font-monospace">{{ $val('os', 'axis') }}</td>
                        <td class="text-center font-monospace">{{ $val('os', 'va') }}</td>
                        <td class="pe-4 text-muted-foreground" style="font-size:var(--text-2xs);opacity:.6;">Distance</td>
                    </tr>

                    {{-- Near Vision (N.V.) --}}
                    <tr>
                        <td class="ps-4 fw-medium text-muted-foreground" style="font-size:var(--text-sm);">N.V.</td>
                        <td class="text-center font-monospace">{{ $val('od', 'nv') }}</td>
                        <td class="text-center" colspan="3"></td>
                        <td class="text-center font-monospace border-start">{{ $val('os', 'nv') }}</td>
                        <td class="text-center" colspan="3"></td>
                        <td class="pe-4 text-muted-foreground" style="font-size:var(--text-2xs);opacity:.6;">Near</td>
                    </tr>

                    {{-- Addition (ADD) --}}
                    <tr>
                        <td class="ps-4 fw-medium text-muted-foreground" style="font-size:var(--text-sm);">ADD</td>
                        <td class="text-center font-monospace">{{ $val('od', 'add') }}</td>
                        <td class="text-center" colspan="3"></td>
                        <td class="text-center font-monospace border-start">{{ $val('os', 'add') }}</td>
                        <td class="text-center" colspan="3"></td>
                        <td class="pe-4 text-muted-foreground" style="font-size:var(--text-2xs);opacity:.6;">Addition</td>
                    </tr>
                </tbody>
            </table>
        </div>

        @if ($record->notes)
            <p class="mt-3 mb-0 bg-light rounded-3 px-3 py-2 text-muted-foreground" style="font-size:var(--text-sm);">
                <span class="fw-medium text-dark">Notes: </span>{{ $record->notes }}
            </p>
        @endif
    </div>
</div>

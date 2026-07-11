@php
    /** @var \App\Models\Customer $customer */
    /** @var \App\Models\EyeRecord|null $record */
    $record = $record ?? null;
    $isEdit = (bool) $record;
    $val = fn ($f) => old($f, $record?->$f ?? '');
@endphp

@if ($errors->any())
    <div class="alert alert-danger alert-dismissible fade show py-3 px-4 rounded-3 mb-4" role="alert">
        <div class="fw-medium mb-2"><i class="bi bi-exclamation-circle me-2"></i>Please fix the following:</div>
        <ul class="mb-0 ps-3">@foreach ($errors->all() as $e)<li class="small">{{ $e }}</li>@endforeach</ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

<form method="POST"
      action="{{ $isEdit ? route('tenant.eye-records.update', $record) : route('tenant.eye-records.store', $customer) }}"
      class="d-flex flex-column gap-4">
    @csrf
    @if ($isEdit) @method('PUT') @endif

    {{-- Patient & examination header --}}
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <p class="text-uppercase text-muted-foreground mb-3" style="font-size:.72rem;letter-spacing:.05em;">Examination details</p>
            <div class="row g-3">
                <div class="col-sm-4">
                    <label for="name" class="form-label small fw-medium mb-2">Customer name</label>
                    <input id="name" type="text" class="form-control" value="{{ $customer->name }}" disabled>
                    <div class="text-muted-foreground" style="font-size:.75rem;margin-top:.25rem;">{{ $customer->phone }}</div>
                </div>
                <div class="col-sm-4">
                    <label for="contact" class="form-label small fw-medium mb-2">Contact number</label>
                    <input id="contact" type="text" class="form-control" value="{{ $customer->phone }}" disabled>
                </div>
                <div class="col-sm-4">
                    <label for="checked_by" class="form-label small fw-medium mb-2">Examined by</label>
                    <input id="checked_by" name="checked_by" type="text" class="form-control"
                           value="{{ old('checked_by', auth()->user()->name) }}" placeholder="Optometrist name">
                </div>
            </div>
        </div>
    </div>

    {{-- Prescription table --}}
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0" style="font-size:.9rem;">
                    <thead class="text-muted-foreground text-uppercase bg-light" style="font-size:.7rem;letter-spacing:.03em;">
                        <tr>
                            <th class="ps-4" style="width:8rem;">Measurement</th>
                            <th colspan="4" class="text-center py-3">RIGHT EYE (OD)</th>
                            <th colspan="4" class="text-center py-3 border-start">LEFT EYE (OS)</th>
                            <th class="pe-4"></th>
                        </tr>
                        <tr style="border-top:1px solid #e2e6ec;">
                            <th class="ps-4 py-2"></th>
                            <th class="text-center" style="width:5.5rem;">SPH</th>
                            <th class="text-center" style="width:5.5rem;">CYL</th>
                            <th class="text-center" style="width:5.5rem;">AXIS</th>
                            <th class="text-center pe-3" style="width:5.5rem;">V/S</th>
                            <th class="text-center ps-3" style="width:5.5rem;">SPH</th>
                            <th class="text-center" style="width:5.5rem;">CYL</th>
                            <th class="text-center" style="width:5.5rem;">AXIS</th>
                            <th class="text-center pe-4" style="width:5.5rem;">V/S</th>
                            <th class="pe-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Distance Vision (D.V.) --}}
                        <tr class="eye-record-row">
                            <td class="ps-4 fw-medium text-muted-foreground" style="font-size:.85rem;">D.V.</td>
                            <td class="text-center"><input type="number" name="od_sph" step="0.25" value="{{ $val('od_sph') }}" class="form-control form-control-sm text-center @error('od_sph') is-invalid @enderror" placeholder="—"></td>
                            <td class="text-center"><input type="number" name="od_cyl" step="0.25" value="{{ $val('od_cyl') }}" class="form-control form-control-sm text-center @error('od_cyl') is-invalid @enderror" placeholder="—"></td>
                            <td class="text-center"><input type="number" name="od_axis" min="0" max="180" step="1" value="{{ $val('od_axis') }}" class="form-control form-control-sm text-center @error('od_axis') is-invalid @enderror" placeholder="—"></td>
                            <td class="text-center pe-3"><input type="text" name="od_va" value="{{ $val('od_va') }}" class="form-control form-control-sm text-center @error('od_va') is-invalid @enderror" placeholder="6/6"></td>
                            <td class="text-center ps-3 border-start"><input type="number" name="os_sph" step="0.25" value="{{ $val('os_sph') }}" class="form-control form-control-sm text-center @error('os_sph') is-invalid @enderror" placeholder="—"></td>
                            <td class="text-center"><input type="number" name="os_cyl" step="0.25" value="{{ $val('os_cyl') }}" class="form-control form-control-sm text-center @error('os_cyl') is-invalid @enderror" placeholder="—"></td>
                            <td class="text-center"><input type="number" name="os_axis" min="0" max="180" step="1" value="{{ $val('os_axis') }}" class="form-control form-control-sm text-center @error('os_axis') is-invalid @enderror" placeholder="—"></td>
                            <td class="text-center pe-4"><input type="text" name="os_va" value="{{ $val('os_va') }}" class="form-control form-control-sm text-center @error('os_va') is-invalid @enderror" placeholder="6/6"></td>
                            <td class="pe-4 text-muted-foreground" style="font-size:.7rem;opacity:.6;">Distance</td>
                        </tr>

                        {{-- Near Vision (N.V.) --}}
                        <tr class="eye-record-row">
                            <td class="ps-4 fw-medium text-muted-foreground" style="font-size:.85rem;">N.V.</td>
                            <td class="text-center"><input type="number" name="od_nv" step="0.25" value="{{ $val('od_nv') }}" class="form-control form-control-sm text-center @error('od_nv') is-invalid @enderror" placeholder="—"></td>
                            <td class="text-center" colspan="3"></td>
                            <td class="text-center ps-3 border-start"><input type="number" name="os_nv" step="0.25" value="{{ $val('os_nv') }}" class="form-control form-control-sm text-center @error('os_nv') is-invalid @enderror" placeholder="—"></td>
                            <td class="text-center" colspan="3"></td>
                            <td class="pe-4 text-muted-foreground" style="font-size:.7rem;opacity:.6;">Near</td>
                        </tr>

                        {{-- Addition (ADD) --}}
                        <tr class="eye-record-row">
                            <td class="ps-4 fw-medium text-muted-foreground" style="font-size:.85rem;">ADD</td>
                            <td class="text-center"><input type="number" name="od_add" step="0.25" value="{{ $val('od_add') }}" class="form-control form-control-sm text-center @error('od_add') is-invalid @enderror" placeholder="—"></td>
                            <td class="text-center" colspan="3"></td>
                            <td class="text-center ps-3 border-start"><input type="number" name="os_add" step="0.25" value="{{ $val('os_add') }}" class="form-control form-control-sm text-center @error('os_add') is-invalid @enderror" placeholder="—"></td>
                            <td class="text-center" colspan="3"></td>
                            <td class="pe-4 text-muted-foreground" style="font-size:.7rem;opacity:.6;">Addition</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- PD & Notes --}}
    <div class="row g-3">
        <div class="col-sm-3">
            <label for="pd" class="form-label small fw-medium mb-2">PD (mm)</label>
            <input id="pd" name="pd" type="number" min="0" max="100" step="0.5"
                   value="{{ $val('pd') }}" class="form-control @error('pd') is-invalid @enderror" placeholder="62">
            <div class="text-muted-foreground" style="font-size:.7rem;margin-top:.3rem;">Pupillary distance</div>
        </div>
        <div class="col-sm-9">
            <label for="notes" class="form-label small fw-medium mb-2">Clinical notes</label>
            <textarea id="notes" name="notes" rows="2" class="form-control" placeholder="Remarks, special observations, follow-up notes…">{{ $val('notes') }}</textarea>
        </div>
    </div>

    {{-- Actions --}}
    <div class="d-flex justify-content-end gap-2">
        <a href="{{ route('tenant.customers.show', $customer) }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-2"></i>{{ $isEdit ? 'Save changes' : 'Save prescription' }}
        </button>
    </div>
</form>

@push('head')
<style>
    .eye-record-row {
        transition: background-color 200ms ease-out;
    }
    .eye-record-row:hover {
        background-color: var(--osms-primary-soft);
    }
    .eye-record-row input.form-control {
        border-color: #e2e6ec;
        transition: all 150ms ease-out;
    }
    .eye-record-row input.form-control:focus {
        border-color: var(--osms-primary);
        box-shadow: 0 0 0 3px rgba(0, 79, 117, 0.1);
    }
    .table thead th {
        border-bottom: 2px solid #e2e6ec;
        padding: 0.75rem 0.5rem;
    }
</style>
@endpush

@push('scripts')
<script>
    // FT-SmartRx (5.2) — smart prescription entry: mirror OD→OS, derive the
    // near/add relationship, and validate ranges client-side (server still
    // enforces the same bounds authoritatively).
    (function () {
        const anchor = document.querySelector('input[name="od_sph"]');
        if (! anchor) return;
        const form = anchor.closest('form');
        const el = (n) => form.querySelector(`[name="${n}"]`);
        const num = (v) => { const x = parseFloat(v); return Number.isFinite(x) ? x : null; };
        const round = (x) => Math.round(x * 100) / 100;
        const dirty = {}; // fields the user has typed into directly (never auto-overwritten)

        // 5.2.2 — Near sphere = Distance sphere + ADD (per eye). Fill whichever of
        // NV / ADD is still "clean" once the other two of {sph, add, nv} are known.
        const derive = (eye) => {
            const sph = num(el(`${eye}_sph`)?.value);
            const add = num(el(`${eye}_add`)?.value);
            const nv = num(el(`${eye}_nv`)?.value);
            if (sph !== null && add !== null && ! dirty[`${eye}_nv`]) {
                if (el(`${eye}_nv`)) el(`${eye}_nv`).value = round(sph + add);
            } else if (sph !== null && nv !== null && ! dirty[`${eye}_add`]) {
                if (el(`${eye}_add`)) el(`${eye}_add`).value = round(nv - sph);
            }
        };

        // 5.2.1 — mirror each OD field to OS until the user edits OS directly.
        ['sph', 'cyl', 'axis', 'va', 'add', 'nv'].forEach((f) => {
            const od = el(`od_${f}`), os = el(`os_${f}`);
            if (! od || ! os) return;
            os.addEventListener('input', () => { dirty[`os_${f}`] = true; derive('os'); });
            od.addEventListener('input', () => {
                if (f === 'nv' || f === 'add') dirty[`od_${f}`] = true; // typed a derived field → keep it
                if (! dirty[`os_${f}`]) os.value = od.value;            // mirror (programmatic, no input event)
                derive('od');
                derive('os');
            });
        });

        // Client-side range validation mirroring StoreEyeRecordRequest (BUG-002).
        const bounds = {
            sph: [-30, 30], cyl: [-15, 15], axis: [0, 180], add: [0, 6],
            dv: [-50, 50], nv: [-50, 50], pd: [0, 100],
        };
        Object.entries(bounds).forEach(([field, [lo, hi]]) => {
            (field === 'pd' ? ['pd'] : [`od_${field}`, `os_${field}`]).forEach((n) => {
                const e = el(n);
                if (! e) return;
                const validate = () => {
                    const v = num(e.value);
                    e.classList.toggle('is-invalid', v !== null && (v < lo || v > hi));
                };
                e.addEventListener('input', validate);
                validate();
            });
        });
    })();
</script>
@endpush

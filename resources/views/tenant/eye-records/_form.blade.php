@php
    /** @var \App\Models\Customer $customer */
    /** @var \App\Models\EyeRecord|null $record */
    $record = $record ?? null;
    $isEdit = (bool) $record;
    $val = function ($f) use ($record) {
        $v = old($f, $record?->$f ?? '');
        if ($v === null || $v === '') return '';
        if (preg_match('/_(sph|cyl|nv|add)$/', $f) && is_numeric($v)) {
            return sprintf('%+.2f', (float) $v);
        }
        return $v;
    };
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
            <p class="text-uppercase text-muted-foreground mb-3" style="font-size:var(--text-xs);letter-spacing:.05em;">Examination details</p>
            <div class="row g-3">
                <div class="col-sm-4">
                    <label for="name" class="form-label small fw-medium mb-2">Customer name</label>
                    <input id="name" type="text" class="form-control" value="{{ $customer->name }}" disabled>
                    <div class="text-muted-foreground" style="font-size:var(--text-xs);margin-top:.25rem;">{{ $customer->phone }}</div>
                </div>
                <div class="col-sm-4">
                    <label for="contact" class="form-label small fw-medium mb-2">Contact number</label>
                    <input id="contact" type="text" class="form-control" value="{{ $customer->phone }}" disabled>
                </div>
                <div class="col-sm-4">
                    <label for="checked_by" class="form-label small fw-medium mb-2">Examined by</label>
                    <input id="checked_by" name="checked_by" type="text" class="form-control"
                           value="{{ old('checked_by', auth()->user()->name) }}" placeholder="Optometrist name" tabindex="1">
                </div>
            </div>
        </div>
    </div>

    {{-- Prescription table --}}
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
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
                            <th class="text-center" style="width:6rem;">SPH</th>
                            <th class="text-center" style="width:6rem;">CYL</th>
                            <th class="text-center" style="width:6rem;">AXIS</th>
                            <th class="text-center" style="width:6rem;">V/S</th>
                            <th class="text-center" style="width:6rem;">SPH</th>
                            <th class="text-center" style="width:6rem;">CYL</th>
                            <th class="text-center" style="width:6rem;">AXIS</th>
                            <th class="text-center" style="width:6rem;">V/S</th>
                            <th class="pe-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Distance Vision (D.V.) --}}
                        <tr class="eye-record-row">
                            <td class="ps-4 fw-medium text-muted-foreground" style="font-size:var(--text-sm);">D.V.</td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="0.25" name="od_sph" value="{{ $val('od_sph') }}" class="form-control form-control-sm text-center is-numeric @error('od_sph') is-invalid @enderror" placeholder="—" tabindex="2"></td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="0.25" name="od_cyl" value="{{ $val('od_cyl') }}" class="form-control form-control-sm text-center is-numeric @error('od_cyl') is-invalid @enderror" placeholder="—" tabindex="3"></td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="1" name="od_axis" value="{{ $val('od_axis') }}" class="form-control form-control-sm text-center is-numeric @error('od_axis') is-invalid @enderror" placeholder="—" tabindex="4"></td>
                            <td class="text-center"><input type="text" name="od_va" value="{{ $val('od_va') }}" class="form-control form-control-sm text-center @error('od_va') is-invalid @enderror" placeholder="6/6" tabindex="5"></td>
                            <td class="text-center border-start"><input type="text" inputmode="text" data-step="0.25" name="os_sph" value="{{ $val('os_sph') }}" class="form-control form-control-sm text-center is-numeric @error('os_sph') is-invalid @enderror" placeholder="—" tabindex="8"></td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="0.25" name="os_cyl" value="{{ $val('os_cyl') }}" class="form-control form-control-sm text-center is-numeric @error('os_cyl') is-invalid @enderror" placeholder="—" tabindex="9"></td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="1" name="os_axis" value="{{ $val('os_axis') }}" class="form-control form-control-sm text-center is-numeric @error('os_axis') is-invalid @enderror" placeholder="—" tabindex="10"></td>
                            <td class="text-center"><input type="text" name="os_va" value="{{ $val('os_va') }}" class="form-control form-control-sm text-center @error('os_va') is-invalid @enderror" placeholder="6/6" tabindex="11"></td>
                            <td class="pe-4 text-muted-foreground" style="font-size:var(--text-2xs);opacity:.6;">Distance</td>
                        </tr>

                        {{-- Near Vision (N.V.) --}}
                        <tr class="eye-record-row">
                            <td class="ps-4 fw-medium text-muted-foreground" style="font-size:var(--text-sm);">N.V.</td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="0.25" name="od_nv" value="{{ $val('od_nv') }}" class="form-control form-control-sm text-center is-numeric @error('od_nv') is-invalid @enderror" placeholder="—" tabindex="6"></td>
                            <td class="text-center" colspan="3"></td>
                            <td class="text-center border-start"><input type="text" inputmode="text" data-step="0.25" name="os_nv" value="{{ $val('os_nv') }}" class="form-control form-control-sm text-center is-numeric @error('os_nv') is-invalid @enderror" placeholder="—" tabindex="12"></td>
                            <td class="text-center" colspan="3"></td>
                            <td class="pe-4 text-muted-foreground" style="font-size:var(--text-2xs);opacity:.6;">Near</td>
                        </tr>

                        {{-- Addition (ADD) --}}
                        <tr class="eye-record-row">
                            <td class="ps-4 fw-medium text-muted-foreground" style="font-size:var(--text-sm);">ADD</td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="0.25" name="od_add" value="{{ $val('od_add') }}" class="form-control form-control-sm text-center is-numeric @error('od_add') is-invalid @enderror" placeholder="—" tabindex="7"></td>
                            <td class="text-center" colspan="3"></td>
                            <td class="text-center border-start"><input type="text" inputmode="text" data-step="0.25" name="os_add" value="{{ $val('os_add') }}" class="form-control form-control-sm text-center is-numeric @error('os_add') is-invalid @enderror" placeholder="—" tabindex="13"></td>
                            <td class="text-center" colspan="3"></td>
                            <td class="pe-4 text-muted-foreground" style="font-size:var(--text-2xs);opacity:.6;">Addition</td>
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
                   value="{{ $val('pd') }}" class="form-control @error('pd') is-invalid @enderror" placeholder="62" tabindex="14">
            <div class="text-muted-foreground" style="font-size:var(--text-2xs);margin-top:.3rem;">Pupillary distance</div>
        </div>
        <div class="col-sm-9">
            <label for="notes" class="form-label small fw-medium mb-2">Clinical notes</label>
            <textarea id="notes" name="notes" rows="2" class="form-control" placeholder="Remarks, special observations, follow-up notes…" tabindex="15">{{ $val('notes') }}</textarea>
        </div>
    </div>

    {{-- Actions --}}
    <div class="d-flex justify-content-end gap-2">
        <a href="{{ route('tenant.customers.show', $customer) }}" class="btn btn-secondary" tabindex="17">Cancel</a>
        <button type="submit" class="btn btn-primary" tabindex="16">
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
        border-color: var(--osms-border);
        transition: all 150ms ease-out;
    }
    .eye-record-row input.form-control:focus {
        border-color: var(--osms-primary);
        box-shadow: 0 0 0 3px rgba(0, 79, 117, 0.1);
    }
    .table thead th {
        border-bottom: 2px solid var(--osms-border);
        padding: 0.75rem 0.5rem;
    }

    /* Remove number spinners for cleaner UI while preserving keyboard navigation */
    .eye-record-row input[type=number]::-webkit-inner-spin-button, 
    .eye-record-row input[type=number]::-webkit-outer-spin-button { 
        -webkit-appearance: none; 
        margin: 0; 
    }
    .eye-record-row input[type=number] {
        -moz-appearance: textfield;
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
            nv: [-50, 50], pd: [0, 100],
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

        // Restore up/down arrow functionality for desktop users on the new text inputs
        document.querySelectorAll('.is-numeric').forEach(input => {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    let v = parseFloat(input.value) || 0;
                    let step = parseFloat(input.getAttribute('data-step')) || 1;
                    if (e.key === 'ArrowDown') step = -step;
                    input.value = (v + step).toFixed(step < 1 ? 2 : 0);
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            });
        });
    })();
</script>
@endpush

@php
    /** @var \App\Models\Customer $customer */
    /** @var \App\Models\EyeRecord|null $record */
    $record = $record ?? null;
    $isEdit = (bool) $record;
    $val = function ($f) use ($record) {
        $v = old($f, $record?->$f ?? '');
        if ($v === null || $v === '') return '';
        // Match the client formatter exactly, or a value would visibly change
        // shape the moment the field is touched. Plano is written bare: "+0.00"
        // reads as a typo.
        if (preg_match('/_(sph|cyl|nv|add)$/', $f) && is_numeric($v)) {
            return (float) $v > 0 ? sprintf('+%.2f', (float) $v) : sprintf('%.2f', (float) $v);
        }
        // AXIS 0 means "not recorded" — an axis is 1..180 and 0 is not a
        // distinct angle. Show it as empty rather than as a bogus reading.
        if (preg_match('/_axis$/', $f) && is_numeric($v) && (int) $v === 0) {
            return '';
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
      {{-- Back after saving returns to the customer, never to this saved form. --}}
      data-leave-on-back="{{ route('tenant.customers.show', $customer) }}"
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
                            <th class="text-center" style="width:6rem;">V/S
                                <button type="button" class="rx-hint-btn" tabindex="-1"
                                        data-bs-toggle="popover" data-bs-trigger="focus hover"
                                        data-bs-placement="top" data-bs-html="true"
                                        data-bs-title="Typing V/S faster"
                                        data-bs-content="No need to reach for <b>/</b> — type the digits and it fills in when you leave the field.<br><br><b>66</b> &rarr; 6/6 &nbsp; <b>69</b> &rarr; 6/9 &nbsp; <b>612</b> &rarr; 6/12<br><b>2020</b> &rarr; 20/20 &nbsp; <b>1</b> &rarr; 1.00<br><br>Anything you write with a <b>/</b> or a <b>.</b> is left exactly as typed, and <b>CF / HM / PL / NPL</b> are accepted."
                                        aria-label="How to type V/S quickly">
                                    <i class="bi bi-info-circle"></i>
                                </button></th>
                            <th class="text-center" style="width:6rem;">SPH</th>
                            <th class="text-center" style="width:6rem;">CYL</th>
                            <th class="text-center" style="width:6rem;">AXIS</th>
                            <th class="text-center" style="width:6rem;">V/S
                                <button type="button" class="rx-hint-btn" tabindex="-1"
                                        data-bs-toggle="popover" data-bs-trigger="focus hover"
                                        data-bs-placement="top" data-bs-html="true"
                                        data-bs-title="Typing V/S faster"
                                        data-bs-content="No need to reach for <b>/</b> — type the digits and it fills in when you leave the field.<br><br><b>66</b> &rarr; 6/6 &nbsp; <b>69</b> &rarr; 6/9 &nbsp; <b>612</b> &rarr; 6/12<br><b>2020</b> &rarr; 20/20 &nbsp; <b>1</b> &rarr; 1.00<br><br>Anything you write with a <b>/</b> or a <b>.</b> is left exactly as typed, and <b>CF / HM / PL / NPL</b> are accepted."
                                        aria-label="How to type V/S quickly">
                                    <i class="bi bi-info-circle"></i>
                                </button></th>
                            <th class="pe-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Distance Vision (D.V.) --}}
                        <tr class="eye-record-row">
                            <td class="ps-4 fw-medium text-muted-foreground" style="font-size:var(--text-sm);">D.V.</td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="0.25" name="od_sph" value="{{ $val('od_sph') }}" autocomplete="off" class="form-control form-control-sm text-center is-numeric @error('od_sph') is-invalid @enderror" placeholder="—" tabindex="2"></td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="0.25" name="od_cyl" value="{{ $val('od_cyl') }}" autocomplete="off" class="form-control form-control-sm text-center is-numeric @error('od_cyl') is-invalid @enderror" placeholder="—" tabindex="3"></td>
                            <td class="text-center"><span class="axis-field"><input type="text" inputmode="text" data-step="1" name="od_axis" value="{{ $val('od_axis') }}" autocomplete="off" class="form-control form-control-sm text-center is-numeric @error('od_axis') is-invalid @enderror" placeholder="—" tabindex="4"></span></td>
                            <td class="text-center"><input type="text" name="od_va" value="{{ $val('od_va') }}" autocomplete="off" class="form-control form-control-sm text-center @error('od_va') is-invalid @enderror" placeholder="6/6" tabindex="5"></td>
                            <td class="text-center border-start"><input type="text" inputmode="text" data-step="0.25" name="os_sph" value="{{ $val('os_sph') }}" autocomplete="off" class="form-control form-control-sm text-center is-numeric @error('os_sph') is-invalid @enderror" placeholder="—" tabindex="8"></td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="0.25" name="os_cyl" value="{{ $val('os_cyl') }}" autocomplete="off" class="form-control form-control-sm text-center is-numeric @error('os_cyl') is-invalid @enderror" placeholder="—" tabindex="9"></td>
                            <td class="text-center"><span class="axis-field"><input type="text" inputmode="text" data-step="1" name="os_axis" value="{{ $val('os_axis') }}" autocomplete="off" class="form-control form-control-sm text-center is-numeric @error('os_axis') is-invalid @enderror" placeholder="—" tabindex="10"></span></td>
                            <td class="text-center"><input type="text" name="os_va" value="{{ $val('os_va') }}" autocomplete="off" class="form-control form-control-sm text-center @error('os_va') is-invalid @enderror" placeholder="6/6" tabindex="11"></td>
                            <td class="pe-4 text-muted-foreground" style="font-size:var(--text-2xs);opacity:.6;">Distance</td>
                        </tr>

                        {{-- Near Vision (N.V.) --}}
                        <tr class="eye-record-row">
                            <td class="ps-4 fw-medium text-muted-foreground" style="font-size:var(--text-sm);">N.V.</td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="0.25" name="od_nv" value="{{ $val('od_nv') }}" autocomplete="off" class="form-control form-control-sm text-center is-numeric @error('od_nv') is-invalid @enderror" placeholder="—" tabindex="6"></td>
                            <td class="text-center" colspan="3"></td>
                            <td class="text-center border-start"><input type="text" inputmode="text" data-step="0.25" name="os_nv" value="{{ $val('os_nv') }}" autocomplete="off" class="form-control form-control-sm text-center is-numeric @error('os_nv') is-invalid @enderror" placeholder="—" tabindex="12"></td>
                            <td class="text-center" colspan="3"></td>
                            <td class="pe-4 text-muted-foreground" style="font-size:var(--text-2xs);opacity:.6;">Near</td>
                        </tr>

                        {{-- Addition (ADD) --}}
                        <tr class="eye-record-row">
                            <td class="ps-4 fw-medium text-muted-foreground" style="font-size:var(--text-sm);">ADD</td>
                            <td class="text-center"><input type="text" inputmode="text" data-step="0.25" name="od_add" value="{{ $val('od_add') }}" autocomplete="off" class="form-control form-control-sm text-center is-numeric @error('od_add') is-invalid @enderror" placeholder="—" tabindex="7"></td>
                            <td class="text-center" colspan="3"></td>
                            <td class="text-center border-start"><input type="text" inputmode="text" data-step="0.25" name="os_add" value="{{ $val('os_add') }}" autocomplete="off" class="form-control form-control-sm text-center is-numeric @error('os_add') is-invalid @enderror" placeholder="—" tabindex="13"></td>
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
    /* The "how do I type this faster?" affordance on the V/S column. Sits in the
       header so it costs no row space, and is skipped by tab order — it must
       never interrupt the entry sequence. */
    .rx-hint-btn {
        border: 0;
        background: none;
        padding: 0 0 0 0.15rem;
        line-height: 1;
        color: var(--osms-faint);
        cursor: help;
        transition: color var(--duration-fast) var(--ease-out), transform var(--duration-fast) var(--ease-out);
    }
    .rx-hint-btn:hover { color: var(--osms-primary); transform: scale(1.12); }

    /* V/S shorthand rewrote what was typed (612 -> 6/12). Two rings expand out
       of the field and the value settles in, so the change is visibly the app's
       doing and not something the optician has to catch on re-reading. */
    .rx-magic { position: relative; }
    .rx-magic::before,
    .rx-magic::after {
        content: '';
        position: absolute;
        inset: 0.15rem 0.25rem;
        border-radius: var(--radius-sm);
        border: 1.5px solid var(--osms-primary);
        pointer-events: none;
        opacity: 0;
        animation: rxRing 0.75s var(--ease-spring) forwards;
    }
    .rx-magic::after { animation-delay: 0.12s; }
    .rx-magic input {
        animation: rxSettle 0.55s var(--ease-spring);
    }
    @keyframes rxRing {
        0%   { opacity: 0.85; transform: scale(0.94); }
        70%  { opacity: 0.15; }
        100% { opacity: 0; transform: scale(1.18); }
    }
    @keyframes rxSettle {
        0%   { transform: translateY(-2px) scale(0.97); color: var(--osms-primary); }
        60%  { transform: translateY(0) scale(1.02); color: var(--osms-primary); }
        100% { transform: none; }
    }
    @media (prefers-reduced-motion: reduce) {
        .rx-magic::before, .rx-magic::after, .rx-magic input { animation: none; }
    }

    /* AXIS is degrees. The sign is an adornment, never part of the value —
       inside the field it would be posted and break parsing. Shown only when
       there is a reading, so empty cells stay clean. */
    .axis-field { position: relative; display: block; }
    .axis-field::after {
        content: '°';
        position: absolute;
        right: 0.45rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--osms-faint);
        font-size: var(--text-xs);
        pointer-events: none;
        opacity: 0;
        transition: opacity var(--duration-fast) var(--ease-out);
    }
    .axis-field.has-value::after { opacity: 1; }
    /* Keep the digits clear of the sign without shifting them off-centre. */
    .axis-field.has-value input { padding-right: 1rem; }

    /* N.V. is calculated from SPH + ADD once both are known, so it reads as an
       output rather than a field awaiting input. */
    .eye-record-row input.is-derived {
        background: var(--surface-sunken);
        color: var(--osms-muted);
        cursor: not-allowed;
        font-weight: 500;
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
<script nonce="{{ csp_nonce() }}">
    // FT-SmartRx (5.2) — smart prescription entry: mirror OD to OS, derive the
    // near/add relationship, and validate client-side (the server enforces the
    // same rules authoritatively in StoreEyeRecordRequest).
    (function () {
        const anchor = document.querySelector('input[name="od_sph"]');
        if (! anchor) return;
        const form = anchor.closest('form');
        const el = (n) => form.querySelector('[name="' + n + '"]');
        const num = (v) => { const x = parseFloat(v); return Number.isFinite(x) ? x : null; };
        const round = (x) => Math.round(x * 100) / 100;
        const dirty = {}; // fields the user typed into directly (never auto-overwritten)

        const isAxis = (input) => /_axis$/.test(input.name);
        const isVa = (input) => /_va$/.test(input.name);

        // The degree sign is an adornment on the wrapper, never part of the
        // value — putting "°" inside the field would post it and break parsing.
        // Only shown when there is a reading, so an empty cell stays clean.
        const markAxisFilled = (input) => {
            input.closest('.axis-field')?.classList.toggle('has-value', String(input.value).trim() !== '');
        };

        // ---- display -------------------------------------------------------
        // A dioptre is meaningless without its sign: "2.00" and "-2.00" are
        // opposite lenses. AXIS is a bearing in degrees, so it is never signed
        // and never fractional. Plano is written bare, because "+0.00" reads as
        // a typo.
        const format = (input) => {
            const raw = String(input.value).trim();
            if (raw === '' || raw === '-' || raw === '+') { input.value = raw; return; }
            if (isVa(input)) { input.value = raw; return; }

            const v = num(raw);
            if (v === null) { input.value = raw; return; }

            input.value = isAxis(input)
                ? String(Math.round(v))
                : (v > 0 ? '+' : '') + v.toFixed(2);
        };

        // THE fix for three separate bugs. Assigning `.value` directly fires no
        // event, so every mirrored or derived field silently skipped BOTH the
        // sign/decimal formatting and the validation pass — the left eye and the
        // computed N.V. stayed unformatted, and a red invalid marker only
        // cleared once you clicked the field yourself. Everything programmatic
        // goes through here instead.
        // `programmatic` marks writes this script made itself. Without it, the
        // input event fired below re-enters the user-edit handler: mirroring OD
        // to OS looked exactly like the user typing into OS, so the very first
        // keystroke marked the left eye as hand-edited and mirroring died after
        // one character. The same re-entry fanned each keystroke out into a
        // cascade of derive/validate passes, which is what made typing lag.
        let programmatic = false;

        const setValue = (input, raw) => {
            if (! input) return;

            const before = input.value;
            input.value = raw === null || raw === undefined ? '' : String(raw);
            format(input);

            // Nothing actually changed — firing an event here would be pure
            // cascade, and derive() calls this on every keystroke.
            if (input.value === before) return;

            programmatic = true;
            try {
                input.dispatchEvent(new Event('input', { bubbles: true }));
            } finally {
                programmatic = false;
            }
        };

        // ---- N.V. = SPH + ADD ----------------------------------------------
        // ADD is what gets prescribed; near sphere follows from it. Verified
        // against the whole legacy dataset: sph + add = nv held in 1,316 of
        // 1,316 checkable rows. So once SPH and ADD are both known, N.V. is not
        // an opinion — it is locked and computed. With ADD still blank, N.V.
        // stays editable and back-computes ADD, which is how a prescription
        // written the other way round is entered.
        const syncLock = (eye) => {
            const nv = el(eye + '_nv');
            if (! nv) return;
            const derived = num(el(eye + '_sph')?.value) !== null && num(el(eye + '_add')?.value) !== null;
            nv.readOnly = derived;
            nv.classList.toggle('is-derived', derived);
            nv.title = derived ? 'Calculated from SPH + ADD. Change ADD to change this.' : '';
        };

        const derive = (eye) => {
            const sph = num(el(eye + '_sph')?.value);
            const add = num(el(eye + '_add')?.value);
            const nv = num(el(eye + '_nv')?.value);

            if (sph !== null && add !== null) {
                setValue(el(eye + '_nv'), round(sph + add));
            } else if (sph !== null && nv !== null && ! dirty[eye + '_add']) {
                setValue(el(eye + '_add'), round(nv - sph));
            }
            syncLock(eye);
        };

        // ---- one path for every real edit ----------------------------------
        // Called only for edits a PERSON made — typing, or an arrow-key step.
        // Everything this script writes goes through setValue() instead, so
        // mirroring can never be mistaken for the user reaching over and
        // editing the left eye by hand.
        const userEdited = (eye, f) => {
            if (eye === 'os') {
                dirty['os_' + f] = true;            // OS was set by hand — stop mirroring it
            } else if (f === 'nv' || f === 'add') {
                dirty['od_' + f] = true;            // a derived field typed directly — keep it
            }

            // Mirror OD to OS until OS has been touched. setValue, not
            // `os.value = ...`, so the mirrored field is formatted and
            // re-validated exactly as if it had been typed.
            if (eye === 'od' && ! dirty['os_' + f]) {
                setValue(el('os_' + f), el('od_' + f).value);
            }

            derive('od');
            derive('os');
            validate();
        };

        // ---- validation -----------------------------------------------------
        // AXIS starts at 1, not 0. An axis is an orientation from 1 to 180; 0 is
        // not a distinct angle (it is 180), and vendors will not be taught that
        // distinction — so 0 is treated as "not recorded" rather than a value.
        const bounds = {
            sph: [-30, 30], cyl: [-15, 15], axis: [1, 180], add: [0, 6],
            nv: [-50, 50], pd: [0, 100],
        };

        // Visual acuity is written several ways and this is NOT a closed list:
        // metric (6/6, 6/9), Snellen (20/20, 20/400), decimal (1.0, 0.5), and
        // the low-vision notations CF / HM / PL / NPL. Suffixes matter too —
        // "6/9+" means slightly better than 6/9 and appears in the real data. A
        // dropdown was considered and rejected: this store's own records contain
        // 6/4, 6/5, 6/8, 6/10 and 6/9+, none of which are on any standard list.
        const VA_PATTERN = /^(?:(?:6|20)\/\d{1,3}(?:\.\d)?[+-]?\d?|0?\.\d{1,2}|[12](?:\.\d{1,2})?|CF|HM|PL|NPL)$/i;

        const vaOk = (raw) => {
            const v = raw.trim();
            if (v === '') return true;
            if (! VA_PATTERN.test(v)) return false;
            // Decimal notation runs 0.05 (worst) to 2.0 (best).
            if (! v.includes('/') && /^[0-9.]+$/.test(v)) {
                const d = parseFloat(v);
                return d >= 0.05 && d <= 2.0;
            }
            return true;
        };

        const mark = (input, bad, message) => {
            if (! input) return;
            input.classList.toggle('is-invalid', bad);
            input.setCustomValidity(bad ? message : '');
            if (bad) input.title = message;
        };

        const validate = () => {
            Object.entries(bounds).forEach(([field, range]) => {
                const lo = range[0], hi = range[1];
                (field === 'pd' ? ['pd'] : ['od_' + field, 'os_' + field]).forEach((n) => {
                    const e = el(n);
                    if (! e) return;
                    const v = num(e.value);
                    mark(e, v !== null && (v < lo || v > hi),
                        field === 'axis'
                            ? 'Axis runs from 1 to 180 degrees.'
                            : 'Enter a value between ' + lo + ' and ' + hi + '.');
                });
            });

            ['od', 'os'].forEach((eye) => {
                // Cylinder and axis are a pair. A cylinder power says HOW MUCH
                // astigmatism there is; the axis says WHICH WAY it lies. One
                // without the other cannot be dispensed.
                const cyl = el(eye + '_cyl'), axis = el(eye + '_axis');
                if (cyl && axis) {
                    const c = num(cyl.value), a = num(axis.value);
                    const hasCyl = c !== null && c !== 0;
                    const hasAxis = a !== null && a !== 0;
                    if (hasCyl && ! hasAxis) {
                        mark(axis, true, 'A cylinder power needs an axis (1-180).');
                    } else if (! hasCyl && hasAxis) {
                        mark(axis, true, 'An axis needs a cylinder power, or clear the axis.');
                    }
                }

                const va = el(eye + '_va');
                if (va) mark(va, ! vaOk(va.value), 'Try 6/6, 20/20, 1.0, or CF / HM / PL.');
            });
        };

        // Shorthand rewrites what the user typed, so it must never be silent —
        // "612" meant as 6.12 becomes 6/12, and without a signal that goes
        // unnoticed. Two rings expand out of the field and the new value settles
        // in, so the change is visibly attributable to the app.
        const flashMagic = (input) => {
            const cell = input.closest('td') || input.parentElement;
            if (! cell) return;

            cell.classList.remove('rx-magic');
            void cell.offsetWidth;        // restart the animation on a repeat edit
            cell.classList.add('rx-magic');
            setTimeout(() => cell.classList.remove('rx-magic'), 900);
        };

        // ---- V/S shorthand ---------------------------------------------------
        // Reaching for "/" mid-examination is slow, and the numerator is almost
        // always 6 (or 20). So a bare run of digits is expanded on the way out
        // of the field: 66 -> 6/6, 69 -> 6/9, 612 -> 6/12, 2020 -> 20/20. A
        // separator typed by hand is accepted too: "6 9" and "6-9" become 6/9.
        //
        // Unambiguous by construction: decimal acuities contain a ".", so they
        // are never touched, and no valid decimal is a bare 2+ digit run (the
        // scale stops at 2.0).
        const expandVa = (input) => {
            const raw = String(input.value).trim();
            if (raw === '') return false;

            // Anything the user already made explicit is left completely alone:
            // a slash means they typed the fraction, a dot means they mean a
            // decimal acuity (0.15, 0.5, 2.0 — all valid), and letters mean a
            // low-vision notation. Shorthand must never fight what was typed.
            if (/[\/.]/.test(raw) || /[a-z]/i.test(raw)) return false;

            // A hand-typed separator becomes the slash. NOT "." — that is a
            // decimal point, and treating it as a separator turned 0.15 into
            // 0/15 and 2.0 into 2/0.
            let v = raw.replace(/^(\d{1,2})\s*[-\s,]\s*(\d{1,3})$/, '$1/$2');

            if (/^\d+$/.test(v)) {
                if (v === '1' || v === '2') {
                    // Decimal acuity, written to two places like every other
                    // measurement on this form.
                    v = v + '.00';
                } else if (v.startsWith('20') && v.length >= 3) {
                    v = '20/' + v.slice(2);
                } else if (v.startsWith('6') && v.length >= 2) {
                    v = '6/' + v.slice(1);
                }
            }

            if (v === raw) return false;

            setValue(input, v);
            flashMagic(input);

            return true;
        };

        // ---- wiring ---------------------------------------------------------
        form.querySelectorAll('.is-numeric, [name$="_va"]').forEach((input) => {
            // AXIS 0 in the stored data means "not recorded" (one legacy row);
            // show it as empty rather than as a bogus angle.
            if (isAxis(input) && num(input.value) === 0) input.value = '';

            const eye = input.name.slice(0, 2);
            const field = input.name.slice(3);

            format(input);
            if (isAxis(input)) markAxisFilled(input);

            input.addEventListener('input', () => {
                if (isAxis(input)) markAxisFilled(input);
                // A write this script made: it has already been formatted, and
                // the edit that caused it is running its own pass. Re-entering
                // here is what broke mirroring and made typing lag.
                if (programmatic) { validate(); return; }
                userEdited(eye, field);
            });

            input.addEventListener('blur', () => {
                // Expansion happens on blur, but mirroring happens on input —
                // so without re-running the edit path the right eye showed the
                // expanded "20/20" while the left kept the raw "2020".
                const expanded = isVa(input) ? expandVa(input) : false;
                format(input);
                if (isAxis(input)) markAxisFilled(input);
                if (expanded) userEdited(eye, field);
                validate();
            });

            if (input.classList.contains('is-numeric')) {
                input.addEventListener('keydown', (e) => {
                    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
                    e.preventDefault();
                    if (input.readOnly) return;
                    let step = parseFloat(input.getAttribute('data-step')) || 1;
                    if (e.key === 'ArrowDown') step = -step;
                    setValue(input, (num(input.value) || 0) + step);
                    // An arrow step is a real edit, so it must mirror and derive
                    // like typing does — setValue alone deliberately does not.
                    userEdited(eye, field);
                });
            }
        });

        ['od', 'os'].forEach(syncLock);
        validate();
    })();
</script>
@endpush

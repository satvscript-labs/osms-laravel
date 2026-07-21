@php
    /** @var \App\Models\Customer|null $customer */
    $customer = $customer ?? null;
    $isEdit = (bool) $customer;

    $codes = ['+91', '+1', '+44', '+971', '+61', '+65', '+880', '+977'];

    // Stored phone is normalised as "{code} {national}" (e.g. "+91 9876543210").
    // Split it back for the form; old() (a failed submit) takes precedence.
    $storedPhone = (string) ($customer->phone ?? '');
    $storedCode = str_contains($storedPhone, ' ') ? \Illuminate\Support\Str::before($storedPhone, ' ') : '+91';
    $storedNational = str_contains($storedPhone, ' ') ? \Illuminate\Support\Str::afterLast($storedPhone, ' ') : $storedPhone;

    $oldCode = old('country_code', $storedCode);
    $oldNational = old('phone')
        ? \Illuminate\Support\Str::of(old('phone'))->afterLast(' ')->toString()
        : $storedNational;

    $cancelUrl = $isEdit ? route('tenant.customers.show', $customer) : route('tenant.customers.index');
@endphp

@if ($errors->any())
    <div class="alert alert-danger py-2 px-3 small rounded-3">{{ $errors->first() }}</div>
@endif

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-4">
        <form method="POST"
              action="{{ $isEdit ? route('tenant.customers.update', $customer) : route('tenant.customers.store') }}"
              class="d-flex flex-column gap-3">
            @csrf
            @if ($isEdit) @method('PUT') @endif
            <div class="row g-3">
                <div class="col-sm-6">
                    <label for="name" class="form-label small fw-medium mb-1">Full name *</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $customer->name ?? '') }}"
                           class="form-control @error('name') is-invalid @enderror"
                           required autofocus placeholder="Rahul Kumar">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6">
                    <label for="phone" class="form-label small fw-medium mb-1">Phone *</label>
                    <div class="input-group">
                        <select name="country_code" class="form-select flex-grow-0 w-auto" aria-label="Country code">
                            @foreach ($codes as $code)
                                <option value="{{ $code }}" @selected($oldCode === $code)>{{ $code }}</option>
                            @endforeach
                        </select>
                        <input id="phone" name="phone" type="tel" value="{{ $oldNational }}"
                               class="form-control @error('phone') is-invalid @enderror"
                               required placeholder="98765 43210"
                               inputmode="numeric" maxlength="10" pattern="\d{10}"
                               oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="col-sm-4">
                    <label for="birthday" class="form-label small fw-medium mb-1">Birthday</label>
                    @php $bday = old('birthday', optional($customer->birthday ?? null)->format('Y-m-d')); @endphp
                    <input id="birthday" name="birthday" type="date" max="{{ now()->toDateString() }}" value="{{ $bday }}"
                           class="form-control @error('birthday') is-invalid @enderror">
                    <div class="text-muted-foreground" style="font-size:var(--text-2xs);margin-top:.25rem;">We'll calculate the age</div>
                    @error('birthday')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-4">
                    <label for="age" class="form-label small fw-medium mb-1">Age</label>
                    <input id="age" name="age" type="number" min="0" max="150" value="{{ old('age', $customer->age ?? '') }}"
                           class="form-control @error('age') is-invalid @enderror" placeholder="or enter directly"
                           inputmode="numeric" oninput="if(this.value.length>3)this.value=this.value.slice(0,3)">
                    @error('age')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-4">
                    <label for="gender" class="form-label small fw-medium mb-1">Gender</label>
                    @php $g = old('gender', $customer->gender ?? ''); @endphp
                    <select id="gender" name="gender" class="form-select">
                        <option value="">Prefer not to say</option>
                        <option value="male" @selected($g === 'male')>Male</option>
                        <option value="female" @selected($g === 'female')>Female</option>
                        <option value="other" @selected($g === 'other')>Other</option>
                    </select>
                </div>
            </div>

            {{-- PRIV-01 — consent capture (DPDP). Data consent is required; the whole
                 row is tappable for speed. WhatsApp opt-in stays optional. --}}
            @php
                $consentChecked = old('data_consent', ($customer && $customer->data_consent_at) ? '1' : null);
                $waOptIn = old('whatsapp_opt_in', ($customer && $customer->whatsapp_opt_in) ? '1' : null);
            @endphp
            <div class="rounded-3 p-2" style="background: var(--surface-sunken);">
                <p class="section-label mb-1 px-2 pt-1">Consent</p>
                <label class="consent-row" for="data_consent">
                    <input class="form-check-input @error('data_consent') is-invalid @enderror" type="checkbox"
                           name="data_consent" value="1" id="data_consent" @checked($consentChecked)>
                    <span class="small">Consents to storing their details &amp; prescription <span class="text-danger">*</span></span>
                </label>
                <label class="consent-row" for="whatsapp_opt_in">
                    <input class="form-check-input" type="checkbox" name="whatsapp_opt_in" value="1"
                           id="whatsapp_opt_in" @checked($waOptIn)>
                    <span class="small">Agrees to WhatsApp updates</span>
                </label>
                @error('data_consent')<div class="small text-danger px-2">{{ $message }}</div>@enderror
                @if ($isEdit && $customer->isMinor())
                    <p class="mb-0 mt-1 px-2 fw-semibold" style="font-size:var(--text-2xs); color: var(--tone-amber);">
                        <i class="bi bi-exclamation-triangle me-1"></i>Under 18 — record the guardian's consent.
                    </p>
                @endif
            </div>

            <div class="d-flex justify-content-end gap-2 mt-2">
                <a href="{{ $cancelUrl }}" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>{{ $isEdit ? 'Save changes' : 'Save customer' }}
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    // PRIV-01 — ticking data consent auto-ticks WhatsApp opt-in (faster capture);
    // staff can still untick WhatsApp afterwards.
    (function () {
        const dc = document.getElementById('data_consent');
        const wa = document.getElementById('whatsapp_opt_in');
        if (!dc || !wa) return;
        dc.addEventListener('change', () => { if (dc.checked) wa.checked = true; });
    })();

    // 5.5 — derive age from the birthday. When a birthday is set, the age is
    // computed and the field locked; clearing the birthday makes age editable again.
    (function () {
        const bday = document.getElementById('birthday');
        const age = document.getElementById('age');
        if (! bday || ! age) return;
        const sync = () => {
            if (! bday.value) { age.readOnly = false; return; }
            const b = new Date(bday.value), now = new Date();
            let a = now.getFullYear() - b.getFullYear();
            const m = now.getMonth() - b.getMonth();
            if (m < 0 || (m === 0 && now.getDate() < b.getDate())) a--;
            if (a >= 0 && a <= 150) { age.value = a; age.readOnly = true; }
        };
        bday.addEventListener('change', sync);
        sync();
    })();
</script>
@endpush

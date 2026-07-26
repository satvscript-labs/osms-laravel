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
              class="d-flex flex-column gap-3"
              x-data="customerForm()" @submit="onSubmit($event)">
            @csrf
            @if ($isEdit) @method('PUT') @endif
            <div class="row g-3">
                <div class="col-sm-6">
                    <label for="name" class="form-label small fw-medium mb-1">Full name *</label>
                    <input id="name" name="name" type="text" x-model="name"
                           class="form-control @error('name') is-invalid @enderror"
                           required autofocus placeholder="Rahul Kumar">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6">
                    {{-- SHARE-01 — optional: a customer who won't give a number is
                         still a customer. --}}
                    <label for="phone" class="form-label small fw-medium mb-1">Phone</label>
                    <div class="input-group">
                        <select name="country_code" class="form-select flex-grow-0 w-auto" aria-label="Country code"
                                x-model="code" @change="lookup()">
                            @foreach ($codes as $code)
                                <option value="{{ $code }}" @selected($oldCode === $code)>{{ $code }}</option>
                            @endforeach
                        </select>
                        <input id="phone" name="phone" type="tel"
                               class="form-control @error('phone') is-invalid @enderror"
                               placeholder="98765 43210"
                               inputmode="numeric" maxlength="10" pattern="\d{10}"
                               x-model="national" @input="sanitise(); lookup()">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="text-muted-foreground" style="font-size:var(--text-2xs);margin-top:.25rem;">
                        Optional — families may share one number
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

            {{-- SHARE-01 — who else is already on this number. --}}
            @include('tenant.customers.partials.household-chooser')

            {{-- PRIV-01 — consent capture (DPDP). Data consent is required; the whole
                 row is tappable for speed. WhatsApp opt-in stays optional. --}}
            @php
                $consentChecked = old('data_consent', ($customer && $customer->data_consent_at) ? '1' : null);
                $waOptIn = old('whatsapp_opt_in', ($customer && $customer->whatsapp_opt_in) ? '1' : null);
            @endphp
            <div class="rounded-3 p-2" style="background: var(--surface-sunken);">
                <p class="section-label mb-1 px-2 pt-1">Consent</p>
                {{-- Ticking data consent auto-ticks WhatsApp (faster capture); staff can
                     untick it after. This MUST go through Alpine rather than setting
                     .checked directly — a programmatic .checked fires no event, so
                     x-model never sees it and the shared-number acknowledgement below
                     would stay hidden until a failed submit revealed it server-side. --}}
                <label class="consent-row" for="data_consent">
                    <input class="form-check-input @error('data_consent') is-invalid @enderror" type="checkbox"
                           name="data_consent" value="1" id="data_consent"
                           x-model="dataConsent" @change="if (dataConsent) waOptIn = true">
                    <span class="small">Consents to storing their details &amp; prescription <span class="text-danger">*</span></span>
                </label>
                <label class="consent-row" for="whatsapp_opt_in">
                    <input class="form-check-input" type="checkbox" name="whatsapp_opt_in" value="1"
                           id="whatsapp_opt_in" @checked($waOptIn) x-model="waOptIn">
                    <span class="small">Agrees to WhatsApp updates</span>
                </label>

                {{-- SHARE-01 / PRIV — consent is per person, but a handset is shared.
                     Opting in on a household number means updates about THIS customer
                     land on a phone a relative may be holding, so it takes a
                     deliberate acknowledgement. Height-animated, never a pop-in. --}}
                <div class="reveal" :class="{ 'reveal-open': waOptIn && householdMembers.length }">
                    <div class="reveal-inner">
                        <label class="consent-row align-items-start" for="whatsapp_shared_ack"
                               style="background: var(--tone-amber-bg); border-radius: var(--radius-sm);">
                            <input class="form-check-input mt-1 @error('whatsapp_shared_ack') is-invalid @enderror"
                                   type="checkbox" name="whatsapp_shared_ack" value="1" id="whatsapp_shared_ack"
                                   @checked(old('whatsapp_shared_ack'))>
                            <span style="font-size:var(--text-xs); color: var(--tone-amber);">
                                <i class="bi bi-people-fill me-1"></i>
                                <strong>This number is shared.</strong>
                                Messages about
                                <span x-text="householdNewName || 'this customer'"></span>
                                will reach a phone
                                <span x-text="householdMembers.length"></span>
                                other <span x-text="householdMembers.length === 1 ? 'person' : 'people'"></span>
                                also <span x-text="householdMembers.length === 1 ? 'uses' : 'use'"></span>.
                                Confirm they're happy with that.
                            </span>
                        </label>
                    </div>
                </div>

                @error('data_consent')<div class="small text-danger px-2">{{ $message }}</div>@enderror
                @error('whatsapp_shared_ack')<div class="small text-danger px-2">{{ $message }}</div>@enderror
                @if ($isEdit && $customer->isMinor())
                    <p class="mb-0 mt-1 px-2 fw-semibold" style="font-size:var(--text-2xs); color: var(--tone-amber);">
                        <i class="bi bi-exclamation-triangle me-1"></i>Under 18 — record the guardian's consent.
                    </p>
                @endif
            </div>

            <div class="d-flex justify-content-end align-items-center gap-2 mt-2">
                {{-- Explains a disabled button rather than leaving it dead. --}}
                <span class="text-muted-foreground text-sm me-auto" x-show="!householdResolved" x-cloak>
                    <i class="bi bi-arrow-up me-1"></i>Tell us who this is first
                </span>
                <a href="{{ $cancelUrl }}" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-primary" :disabled="!householdResolved">
                    <i class="bi bi-check-lg me-1"></i>{{ $isEdit ? 'Save changes' : 'Save customer' }}
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    // SHARE-01 — the household chooser. A number already in use means a relative,
    // not an error, so the form asks who this is rather than rejecting it.
    function customerForm() {
        return {
            ...window.household({ endpoint: @json(route('tenant.customers.by-phone')) }),

            get householdNewName() { return (this.name || '').trim(); },

            // On this surface, picking an existing person means "I meant them,
            // not a new record" — so open their profile.
            pickHousehold(m) { window.location.href = m.url; },

            name: @json(old('name', $customer->name ?? '')),
            dataConsent: @json((bool) $consentChecked),
            waOptIn: @json((bool) old('whatsapp_opt_in', ($customer && $customer->whatsapp_opt_in))),
            code: @json($oldCode),
            national: @json($oldNational),
            // A customer never shares a number with themselves.
            except: @json($isEdit ? $customer->id : null),

            init() {
                // A failed submit re-renders with the number still filled in; look
                // the household up again so the chooser survives the round trip.
                if (this.national) this.lookup();
            },
            sanitise() {
                this.national = String(this.national || '').replace(/\D/g, '').slice(0, 10);
            },
            lookup() {
                this.checkHousehold(this.national, this.code, this.except);
            },
            onSubmit(e) {
                // Belt and braces — the button is disabled, but a stray Enter key
                // must not slip an unresolved household past the chooser.
                if (!this.householdResolved) e.preventDefault();
            },
        };
    }

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

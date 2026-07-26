{{--
    SHARE-01 — "who else is on this number?"

    A phone number is a household handset, so a number already in use is not an
    error: it usually means a relative. This asks, rather than guessing.

    Driven entirely by an Alpine scope the host page mixes in (see the
    `household()` factory in resources/js — or the inline copies on the customer
    form and order builder). Expects, in the enclosing scope:

      householdLoading   bool     lookup in flight
      householdMembers   array    [{id, name, is_patient, eye_records, age, added}]
      householdNewPerson bool     staff explicitly chose "a new person"
      pickHousehold(m)   fn       use an existing member
      chooseNewPerson()  fn       commit to creating a new person

    Height-animated open/close via .reveal — it never pops in.
--}}
<div class="reveal" :class="{ 'reveal-open': householdLoading || householdMembers.length }">
    <div class="reveal-inner">
        <div class="household mt-2">
            <div class="household-head">
                <i class="bi bi-people-fill"></i>
                <span x-show="householdLoading">Checking this number…</span>
                <span x-show="!householdLoading" x-cloak>
                    This number is already used by
                    <span x-text="householdMembers.length"></span>
                    <span x-text="householdMembers.length === 1 ? 'person' : 'people'"></span>
                </span>
            </div>

            {{-- Shimmer placeholder, never a bare spinner over a blank box. --}}
            <template x-if="householdLoading">
                <div>
                    <div class="household-skeleton">
                        <span class="skeleton rounded-circle" style="width:2.7rem;height:2.7rem;"></span>
                        <span class="skeleton flex-grow-1" style="height:.9rem;max-width:11rem;"></span>
                    </div>
                    <div class="household-skeleton">
                        <span class="skeleton rounded-circle" style="width:2.7rem;height:2.7rem;"></span>
                        <span class="skeleton flex-grow-1" style="height:.9rem;max-width:8rem;"></span>
                    </div>
                </div>
            </template>

            <div class="household-list stagger" x-show="!householdLoading" x-cloak>
                <template x-for="m in householdMembers" :key="m.id">
                    <button type="button" class="household-member" @click="pickHousehold(m)">
                        <span class="person-avatar" x-text="m.name.split(' ').map(w => w[0]).slice(0,2).join('').toUpperCase()"></span>
                        <span class="min-w-0">
                            <span class="d-block fw-medium text-truncate" x-text="m.name"></span>
                            <span class="d-flex align-items-center gap-2 mt-1">
                                <template x-if="m.is_patient">
                                    <span class="osms-badge osms-badge-blue">
                                        <span class="osms-badge-dot"></span>
                                        <span x-text="m.eye_records + (m.eye_records === 1 ? ' test' : ' tests')"></span>
                                    </span>
                                </template>
                                <template x-if="m.age">
                                    <span class="meta-chip"><i class="bi bi-person"></i> <span x-text="m.age"></span> yrs</span>
                                </template>
                                <span class="text-faint text-3xs">Added <span x-text="m.added"></span></span>
                            </span>
                        </span>
                        <span class="household-member-pick">
                            Use this person <i class="bi bi-arrow-right"></i>
                        </span>
                    </button>
                </template>

                {{-- The whole point: adding a relative is normal, not an override. --}}
                <button type="button" class="household-new" :class="{ 'is-chosen': householdNewPerson }"
                        @click="chooseNewPerson()" x-show="householdMembers.length" x-cloak>
                    <i class="bi" :class="householdNewPerson ? 'bi-check-circle-fill' : 'bi-person-plus'"></i>
                    <span x-show="!householdNewPerson">
                        None of these — add <strong x-text="householdNewName || 'this person'"></strong> on the same number
                    </span>
                    <span x-show="householdNewPerson" x-cloak>
                        Adding <strong x-text="householdNewName || 'a new person'"></strong> as a new person
                    </span>
                </button>
            </div>

            {{-- Reassurance once committed, so the choice visibly "took". --}}
            <div class="reveal" :class="{ 'reveal-open': householdNewPerson && !householdLoading }">
                <div class="reveal-inner">
                    <div class="household-confirmed">
                        <i class="bi bi-info-circle mt-1"></i>
                        <span>
                            They'll get their own profile and prescription history, sharing this
                            number with the people above. Nobody's records are mixed.
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@extends('layouts.app')
@section('title', 'Platform')

@section('content')
{{--
    P4 — the operator's knobs. Playbook §5.2 rule 7: the automation switch must
    be reachable "without a deploy", which is why supervised mode reads from the
    database rather than config.
--}}
<div class="p-4 p-md-5">

    <div class="mb-4">
        <p class="section-label mb-1">Platform</p>
        <h1 class="h3 fw-semibold font-display mb-1">Platform</h1>
        <p class="text-muted-foreground mb-0 text-md">
            The switches that govern how the automated lane behaves.
        </p>
    </div>

    {{-- ---- Ops health (P5) ----
         First, because it answers the question you did not know to ask. Every
         tile is an observable measurement; where a fact cannot be known it says
         "unknown" rather than showing a reassuring tick (playbook §9). --}}
    <p class="section-label mb-2">Operations</p>
    <div class="row g-3 mb-4 stagger">
        @foreach ($health as $check)
            <div class="col-6 col-lg-3">
                <div class="card card-lift border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="osms-badge osms-badge-{{ $check['tone'] === 'neutral' ? 'blue' : $check['tone'] }}">
                                <span class="osms-badge-dot"></span>{{ $check['value'] }}
                            </span>
                        </div>
                        <p class="fw-semibold mb-1">{{ $check['label'] }}</p>
                        <p class="text-muted-foreground text-sm mb-0">{{ $check['detail'] }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ---- Supervised mode ---- --}}
    <div class="card border-0 shadow-sm rounded-4 mb-4"
         @if ($supervisedGlobally) style="border-left:3px solid var(--tone-amber) !important;" @endif>
        <div class="card-body p-4">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                <div class="min-w-0">
                    <p class="section-label mb-2">Supervised mode</p>
                    <h2 class="h6 fw-semibold font-display mb-1">
                        @if ($supervisedGlobally)
                            <span class="osms-badge osms-badge-amber"><span class="osms-badge-dot"></span>On</span>
                            Every customer pays through you
                        @else
                            <span class="osms-badge osms-badge-green"><span class="osms-badge-dot"></span>Off</span>
                            Customers can pay online
                        @endif
                    </h2>
                    <p class="text-muted-foreground text-sm mb-0" style="max-width:44rem;">
                        Turning this on closes the self-serve checkout for <strong>every</strong> customer at
                        once — they will be told to contact you, and all billing goes through the panel.
                        Nothing about their access or their data changes, and it is reversible at any time.
                    </p>
                </div>

                <button type="button"
                        class="btn {{ $supervisedGlobally ? 'btn-light' : 'btn-primary' }} flex-shrink-0"
                        data-bs-toggle="modal" data-bs-target="#m-supervised">
                    <i class="bi {{ $supervisedGlobally ? 'bi-unlock' : 'bi-lock' }} me-1"></i>
                    {{ $supervisedGlobally ? 'Turn off' : 'Turn on' }}
                </button>
            </div>
        </div>
    </div>

    {{-- ---- Customers supervised individually ---- --}}
    <p class="section-label mb-2">Billed by hand</p>
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        @forelse ($supervisedAccounts as $account)
            <a href="{{ route('superadmin.accounts.show', $account) }}" class="person-row">
                <span class="person-avatar">{{ mb_strtoupper(mb_substr($account->displayName(), 0, 1)) }}</span>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="fw-semibold text-truncate">{{ $account->displayName() }}</span>
                        <span class="meta-chip">{{ $account->stores_count }} {{ Str::plural('store', $account->stores_count) }}</span>
                    </div>
                    @if ($account->supervised_reason)
                        <div class="text-muted-foreground text-sm mt-1"><em>“{{ $account->supervised_reason }}”</em></div>
                    @endif
                </div>
                <i class="bi bi-chevron-right person-chevron"></i>
            </a>
        @empty
            <p class="text-muted-foreground text-center py-4 mb-0 text-sm">
                Nobody is billed by hand individually.
                @if ($supervisedGlobally)
                    Supervised mode is on platform-wide, so this list is not the whole picture.
                @endif
            </p>
        @endforelse
    </div>

    {{-- ---- Read-only knobs ---- --}}
    <p class="section-label mb-2">Settings</p>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="row g-3">
                @foreach ($knobs as $label => $value)
                    <div class="col-6 col-lg-4">
                        <p class="text-muted-foreground mb-0 text-xs">{{ $label }}</p>
                        <p class="fw-medium mb-0">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
            <p class="text-muted-foreground text-xs mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                These still live in configuration and need a deploy to change. Supervised mode
                deliberately does not — it is the one switch you may need in a hurry.
            </p>
        </div>
    </div>
</div>

<x-operator-modal id="m-supervised"
    :title="$supervisedGlobally ? 'Turn supervised mode off' : 'Turn supervised mode on'"
    :action="route('superadmin.platform.supervised-mode')" method="PATCH"
    :label="$supervisedGlobally ? 'Open self-serve again' : 'Close self-serve'"
    dismiss="Never mind"
    :tone="$supervisedGlobally ? 'primary' : 'danger'"
    icon="bi-sliders"
    :intro="$supervisedGlobally
        ? 'Customers will be able to subscribe and change their plan online again.'
        : 'Every customer will be told to contact you instead of paying online. Their access and data are untouched.'">
    <input type="hidden" name="enabled" value="{{ $supervisedGlobally ? 0 : 1 }}">
    <label class="form-label" for="sv-reason">Why <span style="color:var(--tone-red);">*</span></label>
    <input id="sv-reason" name="reason" type="text" required maxlength="500" class="form-control"
           placeholder="e.g. gateway outage — taking payments by hand">
</x-operator-modal>
@endsection

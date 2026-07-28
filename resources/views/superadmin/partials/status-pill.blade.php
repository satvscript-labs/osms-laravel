{{--
    P2 / design §6.1 — ONE pill treatment per lifecycle state, used identically
    on every superadmin surface. Never a raw Bootstrap `text-bg-*` badge.

    @param string      $status  trialing|active|past_due|canceled|none
    @param string|null $access  active|grace|locked  (optional, refines the tone)
--}}
@php
    $map = [
        'active'   => ['tone' => 'green',  'label' => 'Active'],
        'trialing' => ['tone' => 'blue',   'label' => 'Trial'],
        'past_due' => ['tone' => 'amber',  'label' => 'Past due'],
        'canceled' => ['tone' => '',       'label' => 'Cancelled'],
        'none'     => ['tone' => '',       'label' => 'No subscription'],
    ];
    $s = $map[$status ?? 'none'] ?? $map['none'];

    // A paid store inside its grace window is not simply "active" — say so,
    // because it is the state most likely to need the operator today.
    if (($access ?? null) === 'grace') {
        $s = ['tone' => 'amber', 'label' => 'In grace'];
    }
@endphp

<span class="osms-badge {{ $s['tone'] ? 'osms-badge-' . $s['tone'] : '' }}">
    <span class="osms-badge-dot"></span>{{ $s['label'] }}
</span>

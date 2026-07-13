{{--
    FT-WhatsApp (Phase 6) — the automated "undo" ring. Shown on an order whose
    ready/delivered WhatsApp message is still inside its grace window. The ring
    depletes over the remaining time (re-hydrated client-side from data-sends-at);
    tapping it cancels the message and reverts the status.

    Expects: $order, $msg (a scheduled WhatsAppMessage), optional $variant
    ('block' full-width for cards, 'compact' dial-only for table rows, else inline).
--}}
@php
    $graceSeconds = (int) config('whatsapp.send_grace_seconds', 60);
    $title = $msg->event === 'order_delivered' ? 'Undo — back to Ready' : 'Undo — back to In lab';
    $variantClass = match ($variant ?? 'inline') {
        'compact' => 'wa-undo-compact',
        'block' => 'w-100 mt-2',
        default => '',
    };
@endphp
<button type="button"
        class="wa-undo {{ $variantClass }}"
        data-revert-url="{{ route('tenant.orders.revert', $order) }}"
        data-sends-at="{{ optional($msg->scheduled_for)->toIso8601String() }}"
        data-grace="{{ $graceSeconds }}"
        title="Message sending — tap to undo"
        aria-label="Undo the automated WhatsApp message and revert this order">
    <span class="wa-undo-dial" aria-hidden="true">
        <svg viewBox="0 0 36 36">
            <circle class="wa-undo-track" cx="18" cy="18" r="16"></circle>
            <circle class="wa-undo-progress" cx="18" cy="18" r="16"></circle>
        </svg>
        <i class="bi bi-arrow-counterclockwise"></i>
    </span>
    <span class="wa-undo-text">
        <span class="wa-undo-title">{{ $title }}</span>
        <span class="wa-undo-sub">Message sending…</span>
    </span>
</button>

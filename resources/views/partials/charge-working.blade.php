@props(['invoice', 'compact' => false])

@php
    /**
     * REQ-15 — the arithmetic behind a charge, rendered from the STORED working.
     *
     * Owner requirement: a pro-rata charge must show how it was calculated.
     * This partial is the one renderer, used by the operator's ledger and the
     * customer's billing page alike — so the two cannot describe the same
     * charge differently.
     *
     * It reads `$invoice->calculation` rather than recomputing: by the time
     * anyone opens this, the branch count and the tier may both have moved, and
     * a recomputation would answer with today's numbers instead of the ones the
     * customer was actually charged on.
     */
    $calc = $invoice->calculation ?? null;
    $lines = $calc['lines'] ?? [];
@endphp

@if ($lines)
    <div class="rounded-3 p-3 mt-2" style="background:var(--surface-sunken);">
        <p class="section-label mb-2">
            <i class="bi bi-calculator me-1"></i>How this was worked out
        </p>

        @foreach ($lines as $line)
            <div class="d-flex justify-content-between align-items-baseline gap-3 py-1 text-sm">
                <span class="text-muted-foreground">{{ $line['label'] }}</span>
                <span class="text-end fw-medium">{{ $line['value'] }}</span>
            </div>
        @endforeach

        @if (! empty($calc['formula']))
            <div class="mt-2 pt-2 font-monospace text-xs"
                 style="border-top:1px solid var(--osms-border); word-break:break-word;">
                {{ $calc['formula'] }}
            </div>
        @endif
    </div>
@endif

@if ($invoice->hasDiscount())
    <div class="rounded-3 p-3 mt-2" style="background:var(--tone-green-bg);">
        <div class="d-flex justify-content-between align-items-baseline gap-3 text-sm">
            <span style="color:var(--tone-green);">
                <i class="bi bi-tag me-1"></i>Discount applied
            </span>
            <span class="fw-semibold" style="color:var(--tone-green);">
                − ₹ {{ number_format($invoice->discount_amount, 2) }}
            </span>
        </div>
        <div class="d-flex justify-content-between align-items-baseline gap-3 text-xs text-muted-foreground mt-1">
            <span>Was ₹ {{ number_format($invoice->list_amount, 2) }}</span>
            <span>Paid ₹ {{ number_format($invoice->amount, 2) }}</span>
        </div>
        @unless ($compact)
            @if ($invoice->discount_reason)
                <div class="text-xs text-muted-foreground mt-1"><em>“{{ $invoice->discount_reason }}”</em></div>
            @endif
        @endunless
    </div>
@endif

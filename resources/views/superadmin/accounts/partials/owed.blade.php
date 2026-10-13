{{--
    E2 — Customer 360 "Owed" tab: what this customer has been ASKED for (2.7).

    Open charges first (what they owe now), then the recent closed ones, so a
    reopened bill or a void is explainable from the same list. The working is
    the stored one (same partial the customer sees). Actions live in the row:
    record a payment for it, waive it (₹0, reason) or void it (never owed).
--}}
<div class="card border-0 shadow-sm rounded-4 overflow-hidden">
    @forelse ($charges as $charge)
        @php
            $tone = match ($charge->status) {
                'pending' => 'amber', 'paid' => 'green', default => 'neutral',
            };
        @endphp
        <div class="person-row" style="cursor:default;">
            <span class="person-avatar">
                <i class="bi {{ $charge->kind === 'branch_join' ? 'bi-shop' : 'bi-calendar-check' }}"></i>
            </span>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="fw-semibold {{ in_array($charge->status, ['void', 'superseded'], true) ? 'text-decoration-line-through text-faint' : '' }}">
                        ₹ {{ number_format($charge->amount, 2) }}
                    </span>
                    <span class="meta-chip">{{ $charge->label() }}</span>
                    <span class="osms-badge osms-badge-{{ $tone }}"><span class="osms-badge-dot"></span>{{ $charge->statusLabel() }}</span>
                </div>
                <div class="text-muted-foreground text-sm mt-1 d-flex align-items-center gap-2 flex-wrap">
                    <span class="font-monospace text-xs">{{ $charge->number }}</span>
                    @if ($charge->period_start && $charge->period_end)
                        <span>{{ $charge->period_start->format('d M y') }} → {{ $charge->period_end->format('d M y') }}</span>
                    @endif
                    @if ($charge->isPending() && $charge->due_on)
                        <span>due {{ $charge->due_on->format('d M Y') }}</span>
                    @endif
                    @if ($charge->void_reason)<em class="text-faint">“{{ $charge->void_reason }}”</em>@endif
                </div>

                @include('partials.charge-working', ['invoice' => $charge])
            </div>
            @if ($charge->isPending())
                <div class="d-flex gap-2 ms-2 flex-shrink-0">
                    <button type="button" class="btn btn-primary btn-sm"
                            data-bs-toggle="modal" data-bs-target="#m-payment"
                            data-pay-for="{{ $charge->id }}">
                        Record payment
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-light btn-sm" data-bs-toggle="dropdown" aria-label="More for {{ $charge->number }}">
                            <i class="bi bi-three-dots"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-waive-{{ $charge->id }}">
                                <i class="bi bi-hand-thumbs-up me-2"></i>Waive</button></li>
                            <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#m-void-{{ $charge->id }}">
                                <i class="bi bi-x-circle me-2"></i>Void</button></li>
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    @empty
        <p class="text-muted-foreground text-center py-4 mb-0 text-sm">
            Nothing owed — no bills have been raised for this customer.
        </p>
    @endforelse
</div>

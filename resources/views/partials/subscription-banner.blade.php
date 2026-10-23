{{-- ST-Enforce (S1): trial countdown + renewal due + grace-period warning. Tenant users only.
     E4.5 — the renewal is announced before it is due, with the amount and who can pay. --}}
@php
    $osmsTenant = auth()->check() && ! auth()->user()->isSuperadmin() ? auth()->user()->tenant : null;

    // QA-07 — a closed or suspended STORE is explained by its lock page. A trial
    // banner above "This store has been closed" invited payment for a store we
    // had ended, and contradicted the page it sat on.
    $osmsSub = $osmsTenant && ! $osmsTenant->isBlocked()
        // AUD-02 — via the ACCOUNT, so a second branch still sees its banner.
        ? $osmsTenant->governingSubscription()
        : null;
    $osmsState = $osmsSub?->accessState();

    // E4.5 — a renewal within 7 days, or a period already ended (grace).
    // Worked out only when a banner may show: this runs on every page.
    $osmsRenewal = null;
    if ($osmsSub && $osmsSub->current_period_end && in_array($osmsSub->status, ['active', 'past_due'], true)
        && ! $osmsSub->cancel_at_period_end && in_array($osmsState, ['active', 'grace'], true)) {
        $osmsTz = config('billing.timezone', 'Asia/Kolkata');
        $osmsEnd = \Illuminate\Support\Carbon::parse($osmsSub->current_period_end->toDateString(), $osmsTz);
        $osmsDays = (int) \Illuminate\Support\Carbon::today($osmsTz)->diffInDays($osmsEnd, false);

        if ($osmsState === 'grace' || ($osmsDays >= 0 && $osmsDays <= \App\Services\RenewalClock::LEAD_DAYS)) {
            $osmsClock = app(\App\Services\RenewalClock::class);
            // A-22 — a free period you gave is announced on its last two days, like the emails.
            $osmsChased = $osmsClock->skipReason($osmsSub, $osmsEnd) === null
                || ($osmsState === 'active' && $osmsDays >= 0 && $osmsDays <= 1 && $osmsClock->freePeriodEnding($osmsSub, $osmsEnd));
            $osmsBill = $osmsChased
                ? \App\Models\BillingCharge::query()->where('account_id', $osmsSub->account_id)->pending()
                    ->whereIn('kind', ['first_term', 'renewal'])->whereNull('reopened_at')->latest()->first()
                : null;
            $osmsAccount = $osmsTenant->account;

            $osmsRenewal = [
                'grace' => $osmsState === 'grace',
                'chased' => $osmsChased,
                'end' => $osmsEnd,
                'days' => $osmsDays,
                'until' => $osmsEnd->copy()->addDays($osmsSub->graceDays()),
                'amount' => $osmsChased
                    ? (float) ($osmsBill?->amount ?? app(\App\Services\PriceResolver::class)->renewalPrice($osmsSub))
                    : null,
                // E3-I — only the account's owner pays; FB-05.
                'owner' => $osmsAccount && $osmsAccount->owner_user_id === auth()->id(),
                'ownerName' => $osmsAccount?->owner?->name,
                'supervised' => (bool) $osmsAccount?->isSupervised(),
                // No gateway keys: there is no online payment to send them to.
                'online' => app(\App\Services\BillingService::class)->isConfigured(),
            ];

            // Store staff can't act on a renewal that is still to come; grace concerns everyone.
            if (! $osmsRenewal['grace'] && ! auth()->user()->isStoreAdmin()) {
                $osmsRenewal = null;
            }
        }
    }

    // Billed by hand: there is no checkout to send them to, so never say
    // "Subscribe now" — point at the billing page, which explains.
    // Asked only when a banner will show: it costs queries, on every page.
    $osmsSupervised = ($osmsSub?->status === 'trialing' && $osmsState === 'active')
        && (bool) $osmsTenant?->account?->isSupervised();
@endphp

@if ($osmsSub && $osmsSub->status === 'trialing' && $osmsState === 'active')
    @php $osmsDaysLeft = $osmsSub->trialDaysLeft(); @endphp
    <div class="px-4 px-md-5 pt-4 no-print">
        <div class="alert alert-info alert-dismissible fade show d-flex align-items-center gap-2 mb-0 rounded-3" role="alert">
            <i class="bi bi-stars"></i>
            <div>
                You're on a free trial —
                <strong>{{ $osmsDaysLeft }} {{ \Illuminate\Support\Str::plural('day', $osmsDaysLeft) }}</strong> left.
                @if ($osmsSupervised)
                    Our team handles your billing — <a href="{{ route('tenant.billing.index') }}" class="alert-link">see how to pay</a>.
                @else
                    <a href="{{ route('tenant.billing.index') }}" class="alert-link">Subscribe now</a> to keep your store running.
                @endif
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
@elseif ($osmsRenewal)
    @php
        $r = $osmsRenewal;
        $amountText = $r['amount'] !== null ? '₹ ' . number_format($r['amount'], 2) : null;
        $when = match (true) {
            $r['days'] === 0 => 'today',
            $r['days'] === 1 => 'tomorrow, ' . $r['end']->format('d M'),
            default => 'on ' . $r['end']->format('d M Y'),
        };
    @endphp
    <div class="px-4 px-md-5 pt-4 no-print">
        <div class="alert {{ $r['grace'] ? 'alert-warning' : 'alert-info alert-dismissible fade show' }} d-flex align-items-center gap-2 mb-0 rounded-3" role="alert">
            <i class="bi {{ $r['grace'] ? 'bi-exclamation-triangle-fill' : 'bi-calendar-check' }}"></i>
            <div>
                @if ($r['grace'])
                    Your plan ended on <strong>{{ $r['end']->format('d M Y') }}</strong>.
                    @if ($amountText)
                        Pay <strong>{{ $amountText }}</strong> by <strong>{{ $r['until']->format('d M Y') }}</strong> to keep your store running.
                    @else
                        Your store keeps working until <strong>{{ $r['until']->format('d M Y') }}</strong>.
                    @endif
                @elseif ($amountText)
                    Your renewal of <strong>{{ $amountText }}</strong> is due {{ $when }}.
                @else
                    {{-- A-16 — a cycle a person decided about (voided, comp/extension ending): no amount to chase. --}}
                    Your plan is up for renewal {{ $when }}.
                @endif

                @if (! auth()->user()->isStoreAdmin())
                    Please let your store admin know.
                @elseif ($r['supervised'] || ! $r['online'])
                    Our team handles your billing — <a href="{{ route('tenant.billing.index') }}" class="alert-link">see how to pay</a>.
                @elseif ($r['owner'] && $amountText)
                    <a href="{{ route('tenant.billing.index') }}" class="alert-link">Pay now</a>
                @elseif ($amountText)
                    {{ $r['ownerName'] ? $r['ownerName'] . ', the account owner,' : 'The account owner' }} can pay it online —
                    <a href="{{ route('tenant.billing.index') }}" class="alert-link">see the bill</a>.
                @else
                    <a href="{{ route('tenant.billing.index') }}" class="alert-link">See your subscription</a>.
                @endif
            </div>
            @unless ($r['grace'])
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
            @endunless
        </div>
    </div>
@endif

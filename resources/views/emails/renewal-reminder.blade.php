<x-mail::message>
# {{ $heading }}

Hi {{ $customerName }},

@if ($freePeriod)
The free period on your OSMS plan ends **{{ $stage === 'due_0' ? 'today' : 'tomorrow, ' . $dueText }}**. To carry on without a break, your plan is **{{ $amountText }}**.
@else
@switch($stage)
@case('due_7')
Your OSMS plan renews on **{{ $dueText }}** — in {{ $daysLeft }} {{ \Illuminate\Support\Str::plural('day', $daysLeft) }}. The amount due is **{{ $amountText }}**.
@break
@case('due_1')
Your OSMS plan renews **tomorrow, {{ $dueText }}**. The amount due is **{{ $amountText }}**.
@break
@case('due_0')
Your OSMS plan renews **today**. The amount due is **{{ $amountText }}**.
@break
@case('grace')
Your OSMS plan ended on **{{ $dueText }}** and the renewal of **{{ $amountText }}** has not reached us yet. Your store keeps working until **{{ $untilText }}** — pay before then and nothing is interrupted.
@break
@default
Your OSMS plan ended on **{{ $dueText }}** and the renewal was not paid, so access to your store is paused. Your data is safe. Pay **{{ $amountText }}** and you can carry on where you left off.
@endswitch
@endif

@if ($billNumber)
Bill: {{ $billNumber }}
@endif

@if ($billedByHand)
Your account is billed directly by our team. To pay, reply to this email or write to {{ $support }}.

<x-mail::button :url="$payUrl">
See what is due
</x-mail::button>
@else
<x-mail::button :url="$payUrl">
Pay {{ $amountText }}
</x-mail::button>

Only the account owner can pay online. If you have already paid, thank you — you can ignore this email.
@endif

Thanks,<br>
The {{ config('app.name') }} Team
</x-mail::message>

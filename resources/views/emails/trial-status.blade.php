<x-mail::message>
# {{ $daysLeft === 0 ? 'Your free trial has ended' : 'Your free trial is ending soon' }}

Hi {{ $tenant->store_name }},

@if ($daysLeft === 0)
Your OSMS free trial has ended, so access to your store workspace is now paused.
Subscribe to pick up right where you left off — all your data is safe.
@else
Your OSMS free trial ends in **{{ $daysLeft }} {{ \Illuminate\Support\Str::plural('day', $daysLeft) }}**.
Subscribe now to keep your store running without interruption.
@endif

<x-mail::button :url="route('tenant.billing.index')">
{{ $daysLeft === 0 ? 'Subscribe to continue' : 'View plans' }}
</x-mail::button>

Thanks,<br>
The {{ config('app.name') }} Team
</x-mail::message>

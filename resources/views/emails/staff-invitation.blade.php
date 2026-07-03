<x-mail::message>
# You've been invited to {{ $tenant->store_name }}

You've been invited to join **{{ $tenant->store_name }}** on OSMS as
{{ $invitation->role === 'store_admin' ? 'an admin' : 'a staff member' }}.

Click below to set your password and get started.

<x-mail::button :url="route('invitations.show', $invitation->token)">
Accept invitation
</x-mail::button>

This invitation expires on {{ $invitation->expires_at->format('d M Y') }}. If you weren't
expecting this, you can safely ignore this email.

Thanks,<br>
The {{ config('app.name') }} Team
</x-mail::message>

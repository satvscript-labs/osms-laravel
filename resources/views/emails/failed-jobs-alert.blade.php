<x-mail::message>
# {{ $count }} failed background job(s)

OSMS detected **{{ $count }}** failed job(s) in the queue. Queued work (staff invitations,
trial reminders{{ config('whatsapp.automated_enabled') ? ', WhatsApp sends' : '' }}) may not be
going out.

@if (count($recent))
**Most recent failures:**

<x-mail::table>
| Queue | Failed at | Error |
|:------|:----------|:------|
@foreach ($recent as $row)
| {{ $row['queue'] }} | {{ $row['failed_at'] }} | {{ $row['summary'] }} |
@endforeach
</x-mail::table>
@endif

Investigate on the server:

```
php artisan queue:failed        # list all failures
php artisan queue:retry all     # re-run them once fixed
```

This alert is sent by `osms:monitor-failed-jobs` (scheduled hourly).

Thanks,<br>
OSMS
</x-mail::message>

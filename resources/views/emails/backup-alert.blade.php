<x-mail::message>
# Database backup problem

OSMS could not confirm a recent database backup.

**{{ $problem }}**

@if (count($details))
<x-mail::table>
| Check | Value |
|:------|:------|
@foreach ($details as $label => $value)
| {{ $label }} | {{ $value }} |
@endforeach
</x-mail::table>
@endif

Until this is resolved, **a failure could lose customer data permanently.**

Investigate on the server:

```
ls -lh ~/backups                    # what backups exist
tail -30 ~/backups/backup.log       # why the last run failed
bash scripts/backup-db.sh           # run one now, by hand
```

Also confirm the nightly cron still exists in hPanel → Advanced → Cron Jobs.
This alert is sent by `osms:monitor-backups` (scheduled daily) — it checks that a
recent backup **file** exists, so it also catches the cron being deleted or never firing.

Thanks,<br>
OSMS
</x-mail::message>

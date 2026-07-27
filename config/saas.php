<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SaaS platform settings
    |--------------------------------------------------------------------------
    | Cross-cutting knobs for the SaaS layer (enforcement, email, legal).
    | Business identity values feed the legal pages and (later) invoices.
    */

    // ST-Email (S5): require a verified email before entering the workspace.
    // Keep false until production SMTP is confirmed, then flip on (D-S5b).
    'require_email_verification' => env('SAAS_REQUIRE_EMAIL_VERIFICATION', false),

    // ST-Staff (S3): max users per store (owner + staff). Flat cap at launch;
    // becomes tier-based later via Tenant::seatLimit(). Invitations expire after N days.
    // Cast here (not just at call sites): once a real .env file sets these, bare
    // env() returns a numeric STRING, which crashes Carbon's addDays() with a
    // TypeError (int|float required) — bit ProdDemoSeeder in CI.
    'max_staff' => (int) env('SAAS_MAX_STAFF', 5),
    'invite_days' => (int) env('SAAS_INVITE_DAYS', 7),

    // ST-Legal (S6) / invoices: the registered business behind OSMS.
    //
    // BUG-P10 — these default to EMPTY, never to a bracketed placeholder. A blank
    // value makes the template omit the whole field (see BUG-P02); a placeholder
    // like "[Your GSTIN]" would render literally on the public contact page, which
    // is exactly the defect being fixed.
    'legal_entity' => env('SAAS_LEGAL_ENTITY', 'SatvScript'),
    'gst_number' => env('SAAS_GST_NUMBER', ''),
    'support_email' => env('SAAS_SUPPORT_EMAIL', 'support@osms.satvscript.com'),
    'contact_address' => env('SAAS_CONTACT_ADDRESS', ''),

    /*
    | BUG-P02 — is the platform GST-registered?
    |
    | SatvScript is NOT registered (below threshold), so subscription payments are
    | documented as a plain "Payment Receipt": no GSTIN line, no CGST/SGST split.
    | Previously the PDF rendered an 18%-inclusive tax breakdown under a blank
    | GSTIN — asserting tax was collected by an entity not registered to collect
    | it, on a document a customer might submit for input-tax credit.
    |
    | Flip to true (and set SAAS_GST_NUMBER) once registered: the same template
    | then renders a compliant tax invoice again. Registration is the ONLY switch.
    */
    'gst_registered' => (bool) env('SAAS_GST_REGISTERED', false),

    /*
    | OPS-01 — backup health monitoring.
    | The nightly dump is a cron-driven shell script (PHP exec() is disabled on the
    | host), so the app cannot see whether it ran. `osms:monitor-backups` instead
    | checks that a RECENT backup file exists — which also catches the cron being
    | deleted or never firing, a failure mode that produces no output to alert on.
    | Empty dir => resolved at runtime to $HOME/backups.
    */
    'backup_dir' => env('OSMS_BACKUP_DIR', ''),
    'backup_max_age_hours' => (int) env('OSMS_BACKUP_MAX_AGE_HOURS', 26),
    'backup_min_bytes' => (int) env('OSMS_BACKUP_MIN_BYTES', 1024),

];

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

    // ST-Legal (S6) / invoices: the registered business behind OSMS.
    'legal_entity' => env('SAAS_LEGAL_ENTITY', '[Your Registered Business Name]'),
    'gst_number' => env('SAAS_GST_NUMBER', '[Your GSTIN]'),
    'support_email' => env('SAAS_SUPPORT_EMAIL', 'support@osms.satvscript.com'),
    'contact_address' => env('SAAS_CONTACT_ADDRESS', '[Your Registered Business Address]'),

];

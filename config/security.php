<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy (SEC-04)
    |--------------------------------------------------------------------------
    | csp_enabled — emit the header at all (and generate a per-request nonce).
    | csp_enforce — false sends Content-Security-Policy-Report-Only, which reports
    |               violations WITHOUT blocking anything. Default false so a policy
    |               mistake can never take production down on deploy.
    |
    | Rollout: deploy with enforce=false, open every page (especially billing /
    | Razorpay checkout, the order builder, and the barcode scanner) with the
    | browser console open, confirm there are no CSP violation reports, then set
    | CSP_ENFORCE=true.
    */

    'csp_enabled' => (bool) env('CSP_ENABLED', true),
    'csp_enforce' => (bool) env('CSP_ENFORCE', false),

    /*
    |--------------------------------------------------------------------------
    | Superadmin two-factor (SEC-05)
    |--------------------------------------------------------------------------
    | 2FA is available to every user from their profile. This flag additionally
    | REQUIRES it for superadmin accounts: until they enrol, they're redirected to
    | setup and cannot use the platform.
    |
    | Default false so enabling it is a deliberate act — flipping it on while the
    | only superadmin has no authenticator to hand would lock you out of your own
    | platform. Enrol first, then set SUPERADMIN_REQUIRE_2FA=true.
    */

    'superadmin_require_2fa' => (bool) env('SUPERADMIN_REQUIRE_2FA', false),

];

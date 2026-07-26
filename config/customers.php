<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Profile merge (SHARE-01)
    |--------------------------------------------------------------------------
    |
    | Relaxing phone uniqueness means genuine duplicates will accumulate over
    | time — the same person entered twice with a slightly different name. The
    | merge tool is how a shop repairs that.
    |
    | It is frozen as "Coming soon" in production while it is designed properly.
    | Merging is destructive and irreversible: it re-points orders, payments and
    | prescriptions onto one profile and discards the other. That needs its own
    | undo story and its own backup discipline before it is put in front of a
    | shop, and it is not needed on day one.
    |
    | Gated exactly like WHATSAPP_AUTOMATED: on everywhere except production, so
    | the real behaviour can still be exercised by tests and in development.
    |
    */

    'merge_enabled' => (bool) env('CUSTOMER_MERGE', env('APP_ENV', 'production') !== 'production'),

];

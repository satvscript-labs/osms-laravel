<?php

/*
|--------------------------------------------------------------------------
| WhatsApp integration (FT-WhatsApp)
|--------------------------------------------------------------------------
| Order-lifecycle messaging runs per-tenant in one of three modes
| (off / manual / automated). Phase 3 ships the MANUAL flow only — no Meta
| dependency: a human taps a pre-filled wa.me link. The `automated` keys below
| are declared now so later phases (the Cloud API send engine) don't reshape
| config; they are inert until that engine lands.
|
| See _artifacts/WHATSAPP_INTEGRATION_TECH.md for the full design.
*/

return [

    // Whether the Automated (Meta Cloud API) mode is offered to store owners.
    // It is frozen as "Coming soon" in production while the send engine is still
    // being finished, but stays fully usable in local/dev + tests so it can be
    // built out. Override per-environment with WHATSAPP_AUTOMATED=true|false.
    // (Read APP_ENV directly — the container isn't bootstrapped during config load.)
    'automated_enabled' => (bool) env('WHATSAPP_AUTOMATED', env('APP_ENV', 'production') !== 'production'),

    // Cloud API driver (automated mode only — unused in Phase 3).
    'driver' => env('WHATSAPP_DRIVER', 'log'), // log | meta
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),

    // The revert/undo window before an automated message actually sends.
    'send_grace_seconds' => (int) env('WHATSAPP_SEND_GRACE', 60),

    // Local send hour for birthday wishes (automated).
    'birthday_send_hour' => (int) env('WHATSAPP_BDAY_HOUR', 9),

    // Webhook verification (OSMS as Meta Tech Provider — automated mode).
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),

    // Default dialling code stamped on a bare national number.
    'default_country_code' => env('WHATSAPP_DEFAULT_CC', '+91'),

    /*
    |--------------------------------------------------------------------------
    | Default manual message wording
    |--------------------------------------------------------------------------
    | Used when a tenant hasn't customised its own copy (whatsapp_configs.msg_*).
    | Placeholders resolved server-side: {name} {store} {order} {total} {balance}
    | {ready_date}. Kept short + friendly so the wa.me pre-fill reads well.
    */
    'default_messages' => [
        'order_placed' => "Hi {name}, thank you for your order at {store}! 🙏\n\n"
            . "Order #{order}\nTotal: {total}\nBalance due: {balance}\n\n"
            . "We'll let you know as soon as it's ready. Reply here if you have any questions.",

        'order_ready' => "Hi {name}, good news! ✨\n\n"
            . "Your order #{order} at {store} is *ready for pickup*.\n"
            . "Balance due on collection: {balance}\n\n"
            . "Please visit us at your convenience.",

        'order_delivered' => "Hi {name}, thank you for shopping with {store}! 🕶️\n\n"
            . "We hope you love your new eyewear. Take care of it, and do reach out if you "
            . "need any adjustments. We'd love to see you again!",

        'payment_due' => "Hi {name}, a gentle reminder from {store}. 🙏\n\n"
            . "Order #{order} has a pending balance of {balance}.\n"
            . "Whenever convenient, please drop by to settle it or reply here to arrange payment. "
            . "Thank you!",

        'birthday' => "Happy birthday, {name}! 🎉\n\n"
            . "Everyone at {store} wishes you a wonderful year ahead. "
            . "Drop by for a special birthday treat on us!",
    ],

];

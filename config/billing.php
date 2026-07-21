<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans (tiers)
    |--------------------------------------------------------------------------
    | Display metadata for each tier. Prices are in INR/month. The actual
    | Razorpay plan_id used to create the subscription comes from
    | config('services.razorpay.plans.<tier>').
    */

    'trial_days' => 14,

    /*
    |--------------------------------------------------------------------------
    | Access enforcement (ST-Enforce / S1)
    |--------------------------------------------------------------------------
    | grace_days — how long a `past_due` sub (or a paid `active` sub whose
    | renewal webhook is late) keeps working before the workspace hard-locks.
    | timezone — the day-boundary a trial/period expiry is measured against.
    | current_period_end is a pure date; expiry is end-of-day in this timezone.
    */

    'grace_days' => 7,

    'timezone' => 'Asia/Kolkata',

    // GST rate (%) used to split subscription invoices for the PDF (India).
    'gst_rate' => env('BILLING_GST_RATE', 18),

    // Number of billing cycles a new subscription runs before it ends. Razorpay
    // requires a total_count; these are long enough to be effectively ongoing.
    'cycles' => [
        'monthly' => 120, // 10 years of monthly charges
        'yearly' => 10,   // 10 years of annual charges
    ],

    /*
    | Single "Basic" tier at launch, billable monthly or yearly (annual = a
    | discount vs 12× monthly). Each interval maps to its own Razorpay plan id
    | in config('services.razorpay.plans.basic.<interval>').
    */
    'plans' => [
        'basic' => [
            'name' => 'Basic',
            'monthly_price' => 499,
            'yearly_price' => 4999,
            // DOC-03 — this copy is customer-facing (billing page). Keep it to what
            // actually ships: the Excel-export feature was removed, so it was dropped.
            'features' => [
                'Patients & prescriptions',
                'Inventory + barcode POS',
                'Order kanban & receipts',
                'Tax invoices & GST breakup',
                'Analytics & profit reports',
                'WhatsApp customer messaging',
            ],
        ],
    ],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans (tiers)
    |--------------------------------------------------------------------------
    | Display metadata for each tier. Prices here are only the seed for the
    | Plans table: every price charged comes from App\Services\PriceResolver
    | (CLAUDE.md rule 3). E3 pays with Razorpay Orders — there are no gateway plans.
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

    /*
    | Single "Basic" tier at launch, billable monthly or yearly (annual = a
    | discount vs 12× monthly). Seed values only — see the note above.
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

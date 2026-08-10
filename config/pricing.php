<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stripe Price IDs
    |--------------------------------------------------------------------------
    |
    | Centralized Stripe price IDs. Update these via .env variables to avoid
    | hardcoding price IDs throughout the application code.
    |
    */

    'pro_monthly' => env('STRIPE_PRICE_PRO_MONTHLY', 'price_1T9PuUCqOt3Mipp1ZzEvHkJG'),
    'agency_monthly' => env('STRIPE_PRICE_AGENCY_MONTHLY', 'price_1T9PudCqOt3Mipp1bJUzv0EC'),
    'enterprise' => env('STRIPE_PRICE_ENTERPRISE', 'price_1T9PueCqOt3Mipp1qjPMUx7i'),

    /*
    |--------------------------------------------------------------------------
    | Domain Limits Per Plan
    |--------------------------------------------------------------------------
    */
    'domain_limits' => [
        'free' => 1,
        'pro' => 10,
        'agency' => 9999,
        'enterprise' => 99999,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scan Limits Per Plan (per month)
    |--------------------------------------------------------------------------
    */
    'scan_limits' => [
        'free' => 5,
        'pro' => 100,
        'agency' => 99999,
        'enterprise' => 999999,
    ],
];

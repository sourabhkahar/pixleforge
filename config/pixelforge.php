<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pixelforge API
    |--------------------------------------------------------------------------
    | One API key per environment: each key has its own webhook URL, signing
    | secret and /v1/usage report.
    */

    'base_url' => rtrim((string) env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'), '/'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
    'timeout' => (int) env('PIXELFORGE_TIMEOUT', 15),

    // Reject webhook signatures older than this many seconds (replay protection).
    'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

    /*
    |--------------------------------------------------------------------------
    | Resilience
    |--------------------------------------------------------------------------
    */

    // Webhooks are the primary signal; polling is the safety net for lost
    // deliveries (or local dev without a tunnel).
    'poll_interval' => (int) env('PIXELFORGE_POLL_INTERVAL', 10),
    'poll_give_up_after' => (int) env('PIXELFORGE_POLL_GIVE_UP_MINUTES', 30), // minutes

    // How long we keep retrying a submission that is rate limited / overloaded
    // before failing it and releasing the user's reserved credits.
    'submit_give_up_after' => (int) env('PIXELFORGE_SUBMIT_GIVE_UP_MINUTES', 15), // minutes

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    */

    // Users pay Pixelforge's cost plus this markup (25% = 125/100).
    'markup_percent' => (int) env('PIXELFORGE_MARKUP_PERCENT', 25),

    // GET /v1/pricing is cached this long. Each generation is costed with the
    // rates Pixelforge locked onto it at creation, never with this cache.
    'pricing_cache_seconds' => (int) env('PIXELFORGE_PRICING_CACHE_SECONDS', 600),

    // Used only if GET /v1/pricing is unreachable (values from the docs).
    'fallback_pricing' => [
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ],

    'limits' => [
        'prompt_max' => 4000,
        'reference_images_max' => 4,
    ],

    'sizes' => [
        '1024x1024' => 'Square',
        '1536x1024' => 'Landscape',
        '1024x1536' => 'Portrait',
    ],

    'qualities' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
    ],

    /*
    |--------------------------------------------------------------------------
    | Credits (US dollars)
    |--------------------------------------------------------------------------
    */

    'signup_grant' => env('CREDITS_SIGNUP_GRANT', '5.00'),

    // Amounts offered by the fake "Buy credits" button.
    'purchase_options' => ['5.00', '10.00', '25.00'],
];

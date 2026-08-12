<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'pancake' => [
        'api_key' => env('PANCAKE_API_KEY', ''),
    ],

    'cron' => [
        'secret' => env('CRON_SECRET'),
    ],

    // Explicit request (2026-08-13): show Call Tracker's Hub card as
    // "Coming soon" in production rather than a live link, at least until
    // the call-recording storage question (see Phase 5 of the merge plan —
    // no S3 bucket/volume provisioned yet) is resolved. This gates ONLY the
    // Hub card's display, not the /calls/* routes themselves — someone who
    // knows the direct URL (testing) can still reach it either way.
    // Defaults true so local dev/tests are unaffected; set explicitly false
    // on Railway to show "Coming soon" there.
    'call_tracker' => [
        'enabled' => env('CALL_TRACKER_ENABLED', true),
    ],

];

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        // Existing notification config (keep it)
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],

        // ✅ ADD THIS for Slack → Laravel
        'signing_secret' => env('SLACK_SIGNING_SECRET'),

        // Optional (if you call Slack APIs later)
        'bot_token' => env('SLACK_BOT_TOKEN'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
    ],

    'onedrive' => [
        'client_id' => env('ONEDRIVE_CLIENT_ID'),
        'client_secret' => env('ONEDRIVE_CLIENT_SECRET'),
        'refresh_token' => env('ONEDRIVE_REFRESH_TOKEN'),
        'tenant_id' => env('ONEDRIVE_TENANT_ID'),
        'relay_url' => env('ONEDRIVE_RELAY_URL'),
        'relay_secret' => env('ONEDRIVE_RELAY_SECRET'),
    ],


];

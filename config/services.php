<?php

declare(strict_types=1);

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

    // Sendpipe (FregateLab) — communications control plane. All Keverna email
    // (Mail::to(...)->queue(...)) and WhatsApp OTPs route through here.
    // See sendpipe.org/docs and App\Services\Sendpipe\SendpipeClient.
    'sendpipe' => [
        'api_url' => env('SENDPIPE_API_URL', 'https://sendpipe.fregatelab.com/v1'),
        'api_key' => env('SENDPIPE_API_KEY'),
        'default_from' => env('SENDPIPE_FROM', env('MAIL_FROM_ADDRESS')),
        'whatsapp_otp_template' => env('SENDPIPE_WA_OTP_TEMPLATE', 'kev_otp'),
        'whatsapp_otp_language' => env('SENDPIPE_WA_OTP_LANGUAGE', 'en'),
    ],

    // Paystack — PRD §4.1. The master key handles BOTH subscriptions
    // (FregateLab's billing) AND guest bookings via Subaccounts: each
    // operator has a Paystack subaccount registered against this master
    // account, so transactions are initialised here but settle directly
    // to the operator's bank — Keverna keeps `platform_percent_charge`
    // (default 2%) per transaction without ever holding the funds.
    //
    // `paystack_secret_key` on the tenant row remains as a legacy/escape
    // hatch for operators who want to run their own Paystack account.
    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),

        // Percentage of each guest booking that Paystack routes to the
        // master account (Keverna's cut). Operator receives 100 - this.
        // Float to allow 2.5 etc.; capped 0..100 by the Paystack API.
        'platform_percent_charge' => (float) env('PAYSTACK_PLATFORM_PERCENT_CHARGE', 2.0),
    ],

    // Super-admin concierge content import (property setup pipeline).
    // AI extraction runs one-off per property; results are cached on
    // the property record. Costs land in the ai_extractions audit table.
    'content_import' => [
        // Claude Sonnet 4.6 for HTML → structured JSON extraction.
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model'   => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        ],
        // Google Places API v1 for structured business data.
        'google_places' => [
            'api_key' => env('GOOGLE_PLACES_API_KEY'),
            'cost_usd_per_call' => (float) env('GOOGLE_PLACES_COST_USD', 0.017),
        ],
        // Rough naira-per-USD for logging AI spend in local currency.
        'usd_to_kobo' => (int) env('CONTENT_IMPORT_USD_TO_KOBO', 165000),
        // Soft cap: alert when monthly spend exceeds. Hard cap: refuse.
        'soft_cap_kobo' => (int) env('CONTENT_IMPORT_SOFT_CAP_KOBO', 2_000_000),   // ₦20,000
        'hard_cap_kobo' => (int) env('CONTENT_IMPORT_HARD_CAP_KOBO', 5_000_000),   // ₦50,000
        // Booking / Airbnb HTML fetches cap protects against
        // pathological pages inflating LLM cost.
        'max_html_bytes' => (int) env('CONTENT_IMPORT_MAX_HTML_BYTES', 250_000),
    ],

];

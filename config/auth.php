<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\IdentityAccount;
use App\Models\Operator;

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | The default `web` guard authenticates property operators against their
    | tenant. Admins live on a separate `admin` guard backed by a dedicated
    | model + table — see PRD §8.
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'operators'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'operators',
        ],

        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],

        'corporate' => [
            'driver' => 'session',
            'provider' => 'corporate_members',
        ],

        // Central-domain unified identity (docs/unified-login.md). Backs
        // keverna.com/login and the account chooser. Cross-tenant by
        // design — the model does NOT use BelongsToTenant.
        'identity' => [
            'driver' => 'session',
            'provider' => 'identity_accounts',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'operators' => [
            'driver' => 'eloquent',
            'model' => Operator::class,
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],

        'corporate_members' => [
            'driver' => 'eloquent',
            'model' => \App\Models\CorporateMember::class,
        ],

        'identity_accounts' => [
            'driver' => 'eloquent',
            'model' => IdentityAccount::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    */

    'passwords' => [
        'operators' => [
            'provider' => 'operators',
            'table' => 'operator_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        'admins' => [
            'provider' => 'admins',
            'table' => 'admin_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        'corporate_members' => [
            'provider' => 'corporate_members',
            'table' => 'admin_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        // Central-domain unified identity (docs/unified-login.md).
        // Backs the /password/forgot + /password/reset flow served
        // from keverna.com so a single IdentityAccount can reset
        // once, then use the same password to sign into every
        // tenant they operate.
        'identities' => [
            'provider' => 'identity_accounts',
            'table' => 'identity_account_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];

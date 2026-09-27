<?php

declare(strict_types=1);

use App\Models\IdentityAccount;
use App\Models\Operator;

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | Solo mode has one operator table + one guest membership table.
    | No platform admin guard; property Managers hold the highest role.
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'operators'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'operators',
        ],

        'corporate' => [
            'driver' => 'session',
            'provider' => 'corporate_members',
        ],

        // Unified identity for operator login. Backs /login and any
        // account-scoped flows. Not tenant-scoped by design.
        'identity' => [
            'driver' => 'session',
            'provider' => 'identity_accounts',
        ],
    ],

    'providers' => [
        'operators' => [
            'driver' => 'eloquent',
            'model' => Operator::class,
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

    'passwords' => [
        'operators' => [
            'provider' => 'operators',
            'table' => 'operator_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        'corporate_members' => [
            'provider' => 'corporate_members',
            'table' => 'admin_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        'identities' => [
            'provider' => 'identity_accounts',
            'table' => 'identity_account_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];

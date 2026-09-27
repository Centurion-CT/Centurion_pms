<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name + Path
    |--------------------------------------------------------------------------
    |
    | The path is overridable via env so production deploys can hide it
    | behind a random token (e.g. HORIZON_PATH=horizon-abcd1234). The auth
    | gate in HorizonServiceProvider already blocks non-admins, but obscuring
    | the path raises the bar against drive-by scanning.
    */

    'name' => env('HORIZON_NAME', 'keverna-horizon'),
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'keverna'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    'waits' => [
        // Default + tenant-scoped queues both raise alerts after 60s pending.
        // The slow_jobs queue is for known-long-running work (media imports,
        // batch reports) — its threshold sits much higher so a healthy
        // backlog doesn't page on-call.
        'redis:default' => 60,
        'redis:tenants' => 60,
        'redis:slow' => 600,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,
    'memory_limit' => 96,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Three queues, each on its own supervisor so a noisy queue cannot
    | starve the others:
    |
    |   default   — platform-critical, fast work (webhooks, emails, OTPs)
    |   tenants   — tenant-scoped work that may backlog under a single noisy
    |               tenant (DNS provisioning, media conversions, large
    |               availability recalcs). Isolated from `default` on
    |               purpose: even if one tenant pushes thousands of jobs,
    |               the platform-side queue keeps responding.
    |   slow      — known-long-running batch work (>30s expected) that
    |               shouldn't block the other supervisors. Used for things
    |               like reports + large media imports.
    |
    | A job sends itself to the right queue with ->onQueue('tenants') or
    | (defaults to 'default' if unset). The DNS-provisioning jobs we ship
    | in app/Jobs/Domains already target 'tenants'.
    */

    'defaults' => [
        'default-supervisor' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'tenants-supervisor' => [
            'connection' => 'redis',
            'queue' => ['tenants'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 192,
            'tries' => 1,
            'timeout' => 120,
            'nice' => 5,
        ],
        'slow-supervisor' => [
            'connection' => 'redis',
            'queue' => ['slow'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 600,
            'nice' => 10,
        ],
    ],

    'environments' => [
        'production' => [
            'default-supervisor' => [
                'maxProcesses' => 8,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'tenants-supervisor' => [
                'maxProcesses' => 6,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'slow-supervisor' => [
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
        ],

        'staging' => [
            'default-supervisor' => [
                'maxProcesses' => 4,
            ],
            'tenants-supervisor' => [
                'maxProcesses' => 3,
            ],
            'slow-supervisor' => [
                'maxProcesses' => 1,
            ],
        ],

        'local' => [
            'default-supervisor' => ['maxProcesses' => 2],
            'tenants-supervisor' => ['maxProcesses' => 2],
            'slow-supervisor' => ['maxProcesses' => 1],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];

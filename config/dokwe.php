<?php

declare(strict_types=1);

/*
 * Dokwe integration config. Only `api_key` and `project_id` are environment-
 * specific — keep them in env. `base_url` rarely changes; it's here so tests
 * can swap it for a fake.
 */
return [
    'base_url' => env('DOKWE_API_URL', 'https://dokwe.com/api'),
    'api_key' => env('DOKWE_API_KEY'),
    'project_id' => env('DOKWE_PROJECT_ID'),

    // HTTP timeouts. Dokwe's domain mutation endpoints touch nginx / ACME
    // synchronously; the verify call in particular can sit on the ACME
    // challenge for several seconds. Numbers in seconds.
    'connect_timeout' => env('DOKWE_CONNECT_TIMEOUT', 10),
    'request_timeout' => env('DOKWE_REQUEST_TIMEOUT', 30),

    // Polling cadence for verify state — see DokweClient::pollUntilProvisioned.
    'poll_initial_interval' => 10,  // seconds
    'poll_max_attempts' => 30,      // ~5 min wall-clock when paired with the cadence below

    /*
     * Dokwe Errors — remote error ingest. Every uncaught 500-class
     * exception in production POSTs a compact payload to
     *   {base_url}/ingest/errors/{errors_project_id}
     * with a Bearer token. Reporter is a no-op if either env is unset
     * or in non-production environments.
     */
    'errors' => [
        'enabled'    => env('DOKWE_ERRORS_ENABLED', true),
        'project_id' => env('DOKWE_ERRORS_PROJECT_ID'),
        'token'      => env('DOKWE_ERRORS_TOKEN'),

        // Kept tight so a slow Dokwe response doesn't block dying
        // requests. If we ever move to a queued dispatcher these can
        // relax, but sync-with-tight-timeout is fine for MVP.
        'timeout'    => env('DOKWE_ERRORS_TIMEOUT', 3),

        // Max frames of the stack trace to ship. Balance signal vs
        // payload size; more than ~20 rarely adds value.
        'stack_frames' => env('DOKWE_ERRORS_STACK_FRAMES', 20),

        // Only these environments actually ship. `local` and `testing`
        // stay silent — the log is the local truth.
        'environments' => ['production', 'staging'],
    ],
];

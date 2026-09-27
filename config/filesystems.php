<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Bunny Storage + CDN. Tenant media lives under
        // tenant_{id}/ via TenantPathGenerator. Public delivery is
        // served through the linked Pull Zone (BUNNY_PULL_ZONE_URL),
        // which fronts the storage origin with a global CDN.
        //
        // Region codes (set via BUNNY_REGION):
        //   ''  / 'de'  - Frankfurt (default, no prefix)
        //   'ny'        - New York
        //   'la'        - Los Angeles
        //   'sg'        - Singapore
        //   'syd'       - Sydney
        //   'uk'        - London
        //   'br'        - São Paulo
        //   'jh'        - Johannesburg
        //
        // Spatie media-library reads its disk from MEDIA_DISK; set
        // MEDIA_DISK=bunny to route uploads + conversions here.
        'bunny' => [
            'driver' => 'bunny',
            'storage_zone' => env('BUNNY_STORAGE_ZONE'),
            'api_key' => env('BUNNY_STORAGE_API_KEY'),
            'region' => env('BUNNY_REGION', 'de'),
            'pull_zone_url' => env('BUNNY_PULL_ZONE_URL'),
            'throw' => false,
            'report' => false,
            'visibility' => 'public',
        ],

        // Dokwe Storage — S3-compatible object store fronted by a
        // managed HTTPS endpoint (https://dokwe.com/docs/storage).
        //
        // Dokwe hands out one endpoint per "space" (bucket). Two URLs
        // matter:
        //
        //   - `<slug>-s3.dokwe.com`   — S3 API (SigV4-signed reads + writes)
        //   - `<slug>-file.dokwe.com` — public CDN front for readable spaces
        //
        // We reuse Laravel's built-in `s3` driver — no custom adapter
        // needed. `use_path_style_endpoint = true` is mandatory (the
        // docs are explicit: virtual-hosted style isn't supported).
        // Region is ignored on the server side but SigV4 requires
        // something; the docs recommend `us-east-1`.
        //
        // The `url` config points at the file CDN (not the S3 API), so
        // Spatie's `getUrl()` returns the fast public URL for public
        // spaces. If you provision a private space, set DOKWE_PUBLIC_URL
        // to a signed-URL-serving frontend or leave it empty and use
        // temporary URLs.
        //
        // MEDIA_DISK=dokwe routes Spatie media-library uploads here.
        'dokwe' => [
            'driver' => 's3',
            'key' => env('DOKWE_ACCESS_KEY'),
            'secret' => env('DOKWE_SECRET_KEY'),
            'region' => env('DOKWE_REGION', 'us-east-1'),
            'bucket' => env('DOKWE_BUCKET'),
            'endpoint' => env('DOKWE_ENDPOINT'),
            'url' => env('DOKWE_PUBLIC_URL'),
            'use_path_style_endpoint' => true,
            'throw' => false,
            'report' => false,
            'visibility' => 'public',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

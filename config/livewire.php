<?php

declare(strict_types=1);

/*
 * Only the values we need to override live here — everything else falls
 * through to Livewire's own vendor default (config/livewire.php inside
 * the package). If you want to override anything more, copy the key
 * verbatim from vendor/livewire/livewire/config/livewire.php.
 */
return [

    /*
    |---------------------------------------------------------------------------
    | Temporary File Uploads
    |---------------------------------------------------------------------------
    |
    | Livewire stages every uploaded file under `livewire-tmp/` on the
    | disk defined here *before* the component's action handler ever
    | runs. When `disk` is null, Livewire falls back to Laravel's
    | default filesystem — which on prod is Bunny (`FILESYSTEM_DISK=bunny`
    | in .env). Cloud disks are the wrong place for this: Livewire's
    | `listContents('livewire-tmp', deep: true)` bombs on the Bunny
    | driver and the upload silently dies with a full-page reload.
    |
    | Pinning to `local` keeps temp files on the app server's fast
    | local disk. Media is still moved to the tenant's media disk
    | (`MEDIA_DISK`) by the component's `addMedia()` call downstream.
    */

    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => null,
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];

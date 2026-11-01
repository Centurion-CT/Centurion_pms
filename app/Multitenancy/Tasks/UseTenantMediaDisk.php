<?php

declare(strict_types=1);

namespace App\Multitenancy\Tasks;

use App\Services\Multitenancy\TenantMediaDisk;
use Illuminate\Support\Facades\Storage;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

/**
 * Points media uploads at the current business's own Dokwe Storage space
 * when it has connected one, and back at the platform disk otherwise.
 *
 * A switch task rather than middleware so queue workers get it too: a
 * worker binds a different business per job, and Laravel keeps a disk
 * once it is built, so each switch resets the disk before configuring
 * it. Without that, the next business's thumbnails could land in the
 * previous business's space.
 */
final class UseTenantMediaDisk implements SwitchTenantTask
{
    private readonly string $platformDisk;

    public function __construct()
    {
        $this->platformDisk = (string) config('media-library.disk_name');
    }

    public function makeCurrent(IsTenant $tenant): void
    {
        $this->forgetCurrent();

        app(TenantMediaDisk::class)->configure($tenant);
    }

    public function forgetCurrent(): void
    {
        config()->set('media-library.disk_name', $this->platformDisk);
        config()->offsetUnset('filesystems.disks.'.TenantMediaDisk::DISK_NAME);
        Storage::forgetDisk(TenantMediaDisk::DISK_NAME);
    }
}

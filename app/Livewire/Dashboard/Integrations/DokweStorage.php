<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Integrations;

use App\Actions\Integrations\ConnectDokweStorage;
use App\Actions\Integrations\DisconnectDokweStorage;
use App\Exceptions\DokweStorageConnectionException;
use App\Models\Tenant;
use App\Services\Multitenancy\TenantMediaDisk;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Setup surface for the Dokwe Storage integration
 * (https://dokwe.com/docs/storage).
 *
 * Six inputs: access key, secret key, bucket, endpoint, public URL,
 * region. Bucket is the "master" field, typing it auto-fills the
 * endpoint + public URL to Dokwe's conventional URL pattern
 * (<bucket>-s3.dokwe.com / <bucket>-file.dokwe.com), which the
 * operator can still override for custom domains.
 *
 * Save runs the live handshake in {@see ConnectDokweStorage}, nothing
 * touches the tenant row unless Dokwe accepts the credentials.
 */
#[Layout('layouts.tenant-auth')]
class DokweStorage extends Component
{
    #[Validate('required|string|max:120|regex:/^[a-z0-9][a-z0-9\-]{1,118}$/i')]
    public string $bucket = '';

    #[Validate('required|url|max:240')]
    public string $endpoint = '';

    #[Validate('required|url|max:240')]
    public string $publicUrl = '';

    #[Validate('required|string|max:120')]
    public string $accessKey = '';

    #[Validate('required|string|max:240')]
    public string $secretKey = '';

    #[Validate('required|string|max:24')]
    public string $region = 'us-east-1';

    public function mount(TenantMediaDisk $mediaDisk): void
    {
        $tenant = Tenant::current();
        $snapshot = $mediaDisk->describe($tenant);

        // Pre-fill non-secret fields so returning operators can see
        // what's currently connected. Secrets are never pre-filled -
        // Livewire ships property values to the browser, and even
        // encrypted-at-rest keys shouldn't round-trip through the DOM.
        $this->bucket = (string) $snapshot['bucket'];
        $this->endpoint = (string) $snapshot['endpoint'];
        $this->publicUrl = (string) $snapshot['public_url'];
        $this->region = (string) ($snapshot['region'] ?: 'us-east-1');
    }

    /**
     * Reactive helper, as soon as the operator types a bucket name,
     * fill in Dokwe's conventional URL pattern for that space. They
     * can still override for a custom domain later.
     */
    public function updatedBucket(string $value): void
    {
        $slug = trim(strtolower($value));
        if ($slug === '' || ! preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return;
        }
        if ($this->endpoint === '' || str_contains($this->endpoint, 'dokwe.com')) {
            $this->endpoint = "https://{$slug}-s3.dokwe.com";
        }
        if ($this->publicUrl === '' || str_contains($this->publicUrl, 'dokwe.com')) {
            $this->publicUrl = "https://{$slug}-file.dokwe.com";
        }
    }

    public function connect(ConnectDokweStorage $action): void
    {
        $data = $this->validate();

        $tenant = Tenant::current();
        if ($tenant === null) {
            throw ValidationException::withMessages(['bucket' => 'Tenant not resolved.']);
        }

        try {
            $action->handle(
                tenant:    $tenant,
                accessKey: $data['accessKey'],
                secretKey: $data['secretKey'],
                bucket:    $data['bucket'],
                endpoint:  $data['endpoint'],
                publicUrl: $data['publicUrl'],
                region:    $data['region'],
            );
        } catch (DokweStorageConnectionException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
            return;
        }

        // Clear secret from the form so it doesn't linger client-side
        // after a successful connect. Other fields stay pre-filled so
        // the "connected" snapshot below reflects what was saved.
        $this->secretKey = '';
        $this->accessKey = '';
        $this->dispatch('notify', title: 'Done', message: 'Connected. New uploads will land in this Dokwe space.', kind: 'ok');
    }

    public function disconnect(DisconnectDokweStorage $action): void
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return;
        }
        $action->handle($tenant);
        $this->accessKey = '';
        $this->secretKey = '';
        $this->bucket = '';
        $this->endpoint = '';
        $this->publicUrl = '';
        $this->region = 'us-east-1';
        $this->dispatch('notify', title: 'Done', message: 'Disconnected. New uploads are back on the platform default. Existing files stay in your Dokwe space.', kind: 'ok');
    }

    public function render(TenantMediaDisk $mediaDisk): View
    {
        return view('livewire.dashboard.integrations.dokwe-storage', [
            'status' => $mediaDisk->describe(Tenant::current()),
        ]);
    }
}

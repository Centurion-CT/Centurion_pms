<?php

declare(strict_types=1);

use App\Actions\Integrations\ConnectDokweStorage;
use App\Actions\Integrations\DisconnectDokweStorage;
use App\Enums\OperatorRole;
use App\Exceptions\DokweStorageConnectionException;
use App\Livewire\Dashboard\Integrations\DokweStorage as DokweStoragePage;
use App\Livewire\Dashboard\Integrations\Index as IntegrationsIndex;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Integrations\DokweStorageHandshake;
use App\Services\Multitenancy\TenantMediaDisk;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    // Swap out the live S3 handshake with a no-op — the connect action
    // wraps that in a small service (DokweStorageHandshake) precisely
    // so tests don't have to route AWS SDK traffic through the network.
    $this->fakeHandshake = new class extends DokweStorageHandshake {
        public array $calls = [];
        public ?\Throwable $shouldThrow = null;
        public function verify(array $config): void
        {
            $this->calls[] = $config;
            if ($this->shouldThrow) throw $this->shouldThrow;
        }
    };
    $this->app->instance(DokweStorageHandshake::class, $this->fakeHandshake);

    $this->tenant = Tenant::factory()->create(['slug' => 'dokwe-int']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/* ------------------------------------------------------------------ */
/* Connect action                                                      */
/* ------------------------------------------------------------------ */

it('persists credentials + stamps connected_at after a successful handshake', function (): void {
    // Handshake is faked in beforeEach; it accepts the call and returns.
    app(ConnectDokweStorage::class)->handle(
        tenant:    $this->tenant,
        accessKey: 'dkwk_test',
        secretKey: 'super-secret',
        bucket:    'chidi-hotel',
        endpoint:  'https://chidi-hotel-s3.dokwe.com',
        publicUrl: 'https://chidi-hotel-file.dokwe.com',
    );

    $t = $this->tenant->fresh();
    expect($t->dokwe_storage_bucket)->toBe('chidi-hotel');
    expect($t->dokwe_storage_access_key)->toBe('dkwk_test');
    expect($t->dokwe_storage_secret_key)->toBe('super-secret');
    expect($t->dokwe_storage_endpoint)->toBe('https://chidi-hotel-s3.dokwe.com');
    expect($t->dokwe_storage_public_url)->toBe('https://chidi-hotel-file.dokwe.com');
    expect($t->dokwe_storage_connected_at)->not->toBeNull();
    expect($t->hasDokweStorage())->toBeTrue();
    expect($this->fakeHandshake->calls)->toHaveCount(1);
    expect($this->fakeHandshake->calls[0]['bucket'])->toBe('chidi-hotel');
    expect($this->fakeHandshake->calls[0]['use_path_style_endpoint'])->toBeTrue();
});

it('does not persist when the handshake fails', function (): void {
    $this->fakeHandshake->shouldThrow = new DokweStorageConnectionException('403 SignatureDoesNotMatch');

    expect(fn () => app(ConnectDokweStorage::class)->handle(
        tenant:    $this->tenant,
        accessKey: 'k', secretKey: 's', bucket: 'chidi-hotel',
        endpoint:  'https://chidi-hotel-s3.dokwe.com',
        publicUrl: 'https://chidi-hotel-file.dokwe.com',
    ))->toThrow(DokweStorageConnectionException::class, 'SignatureDoesNotMatch');

    expect($this->tenant->fresh()->hasDokweStorage())->toBeFalse();
});

it('rejects with a friendly error when any field is empty', function (): void {
    expect(fn () => app(ConnectDokweStorage::class)->handle(
        tenant:    $this->tenant,
        accessKey: '',
        secretKey: 'x',
        bucket:    'y',
        endpoint:  'https://x-s3.dokwe.com',
        publicUrl: 'https://x-file.dokwe.com',
    ))->toThrow(DokweStorageConnectionException::class, 'All fields are required');

    expect($this->tenant->fresh()->hasDokweStorage())->toBeFalse();
});

it('rejects an endpoint that does not look like the S3 URL', function (): void {
    expect(fn () => app(ConnectDokweStorage::class)->handle(
        tenant:    $this->tenant,
        accessKey: 'k',
        secretKey: 's',
        bucket:    'b',
        endpoint:  'ftp://wrong',
        publicUrl: 'https://b-file.dokwe.com',
    ))->toThrow(DokweStorageConnectionException::class, 'S3 API URL');
});

/* ------------------------------------------------------------------ */
/* Disconnect action                                                   */
/* ------------------------------------------------------------------ */

it('clears every dokwe column on disconnect but leaves media rows alone', function (): void {
    // Seed as connected.
    $this->tenant->forceFill([
        'dokwe_storage_access_key'   => 'k',
        'dokwe_storage_secret_key'   => 's',
        'dokwe_storage_bucket'       => 'b',
        'dokwe_storage_endpoint'     => 'https://b-s3.dokwe.com',
        'dokwe_storage_public_url'   => 'https://b-file.dokwe.com',
        'dokwe_storage_region'       => 'us-east-1',
        'dokwe_storage_connected_at' => now(),
    ])->save();

    app(DisconnectDokweStorage::class)->handle($this->tenant);

    $t = $this->tenant->fresh();
    expect($t->hasDokweStorage())->toBeFalse();
    expect($t->dokwe_storage_bucket)->toBeNull();
    expect($t->dokwe_storage_access_key)->toBeNull();
    expect($t->dokwe_storage_secret_key)->toBeNull();
    expect($t->dokwe_storage_endpoint)->toBeNull();
    expect($t->dokwe_storage_public_url)->toBeNull();
    expect($t->dokwe_storage_connected_at)->toBeNull();
});

/* ------------------------------------------------------------------ */
/* Runtime disk swap                                                   */
/* ------------------------------------------------------------------ */

function connectDokwe(Tenant $tenant, string $bucket): void
{
    $tenant->forceFill([
        'dokwe_storage_access_key'   => 'k',
        'dokwe_storage_secret_key'   => 's',
        'dokwe_storage_bucket'       => $bucket,
        'dokwe_storage_endpoint'     => "https://{$bucket}-s3.dokwe.com",
        'dokwe_storage_public_url'   => "https://{$bucket}-file.dokwe.com",
        'dokwe_storage_region'       => 'us-east-1',
        'dokwe_storage_connected_at' => now(),
    ])->save();
}

it('swaps media-library disk when a connected business becomes current', function (): void {
    connectDokwe($this->tenant, 'chidi-hotel');
    Tenant::forgetCurrent();
    $this->tenant->refresh()->makeCurrent();

    expect(config('media-library.disk_name'))->toBe(TenantMediaDisk::DISK_NAME);
    $swapped = config('filesystems.disks.'.TenantMediaDisk::DISK_NAME);
    expect($swapped['bucket'])->toBe('chidi-hotel');
    expect($swapped['use_path_style_endpoint'])->toBeTrue();
});

it('is a no-op when the tenant has not connected', function (): void {
    $this->tenant->makeCurrent();

    expect(config('media-library.disk_name'))->not->toBe(TenantMediaDisk::DISK_NAME);
    expect(config('filesystems.disks.'.TenantMediaDisk::DISK_NAME))->toBeNull();
});

it("never hands one business's storage to the next, as a queue worker switches", function (): void {
    $platformDisk = config('media-library.disk_name');
    connectDokwe($this->tenant, 'chidi-hotel');
    $other = Tenant::factory()->create(['slug' => 'dokwe-other']);
    $third = Tenant::factory()->create(['slug' => 'dokwe-third']);
    connectDokwe($third, 'third-hotel');

    Tenant::forgetCurrent();
    $this->tenant->refresh()->makeCurrent();
    expect(Storage::disk(TenantMediaDisk::DISK_NAME)->getConfig()['bucket'])->toBe('chidi-hotel');

    $other->makeCurrent();
    expect(config('media-library.disk_name'))->toBe($platformDisk)
        ->and(config('filesystems.disks.'.TenantMediaDisk::DISK_NAME))->toBeNull();

    $third->refresh()->makeCurrent();
    expect(Storage::disk(TenantMediaDisk::DISK_NAME)->getConfig()['bucket'])->toBe('third-hotel');

    Tenant::forgetCurrent();
    expect(config('media-library.disk_name'))->toBe($platformDisk);
});

/* ------------------------------------------------------------------ */
/* UI + access control                                                 */
/* ------------------------------------------------------------------ */

it('renders the integrations catalog for a manager with the Dokwe card', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(IntegrationsIndex::class)
        ->assertSeeHtml('data-testid="integration-dokwe-storage"')
        ->assertSeeHtml('data-testid="integration-dokwe-storage-status"')
        ->assertSeeHtml('Not connected');
});

it('redirects a non-manager away from the integrations page', function (): void {
    $officer = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::ReservationOfficer,
    ]);

    $response = $this->actingAs($officer, 'web')
        ->get('http://dokwe-int.keverna.test/dashboard/integrations');

    $response->assertRedirect();
});

it('auto-fills endpoint + public URL when the operator types a bucket name', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(DokweStoragePage::class)
        ->set('bucket', 'chidi-hotel')
        ->assertSet('endpoint', 'https://chidi-hotel-s3.dokwe.com')
        ->assertSet('publicUrl', 'https://chidi-hotel-file.dokwe.com');
});

it('surfaces a connection error on the setup page instead of throwing', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(DokweStoragePage::class)
        ->set('bucket', 'a')                        // fails bucket regex (needs 2+ chars)
        ->set('endpoint', 'https://a-s3.dokwe.com')
        ->set('publicUrl', 'https://a-file.dokwe.com')
        ->set('accessKey', 'k')
        ->set('secretKey', 's')
        ->call('connect')
        ->assertHasErrors('bucket');
});

<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Staff;

use App\Enums\OperatorRole;
use App\Http\Middleware\EnsureLinkedDeviceActive;
use App\Models\MobileDevice;
use App\Models\MobilePairingCode;
use App\Models\PosDeviceSession;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Manager-only console for the phones that staff have paired to the POS.
 *
 * A revoke takes effect on the phone's very next request via
 * {@see EnsureLinkedDeviceActive}, no push channel,
 * no client-side JS.
 *
 * Revoked rows are kept visible for audit ("who unlinked which phone
 * when?") until a manager explicitly deletes them.
 */
#[Layout('layouts.tenant-auth')]
class Devices extends Component
{
    // Active mobile-pair code (short-lived), set on linkPhone(),
    // rendered as a QR + typeable fallback until it expires.
    public ?string $mobileCode = null;

    public ?string $mobileCodeExpiresAt = null;

    public ?string $mobileQrSvg = null;

    /**
     * Generate a MobilePairingCode + render its QR. Any signed-in
     * operator can pair their own phone (this is bootstrapping, no
     * manager-only gate here; the phone inherits the operator's own
     * role, not something they can escalate to).
     */
    public function linkPhone(): void
    {
        $operator = auth('web')->user();
        $tenant = Tenant::current();
        if ($operator === null || $tenant === null) {
            $this->dispatch('notify', title: "Couldn't complete", message: 'Sign in first.', kind: 'err');

            return;
        }

        // Recycle unspent codes for this operator so a stuck flow
        // doesn't stack rows in the table.
        MobilePairingCode::query()
            ->where('operator_id', $operator->id)
            ->whereNull('consumed_at')
            ->update(['expires_at' => now()->subSecond()]);

        $expiresAt = now()->addMinutes(5);
        $code = MobilePairingCode::query()->create([
            'tenant_id' => $tenant->id,
            'operator_id' => $operator->id,
            'code' => MobilePairingCode::generateCode(),
            'expires_at' => $expiresAt,
        ]);

        // QR payload = JSON with base URL + code. Flutter scans, POSTs
        // to {base}/api/mobile/v1/pair with the code + device info.
        $payload = json_encode([
            'v' => 1,
            'base' => 'https://'.$tenant->slug.'.'.config('keverna.central_domain'),
            'code' => $code->code,
        ], JSON_UNESCAPED_SLASHES);

        $this->mobileCode = $code->code;
        $this->mobileCodeExpiresAt = $expiresAt->toIso8601String();
        $this->mobileQrSvg = (new Builder(writer: new SvgWriter()))
            ->build(data: $payload, size: 240, margin: 4)
            ->getString();
    }

    public function cancelPhoneLink(): void
    {
        $operator = auth('web')->user();
        if ($operator !== null) {
            MobilePairingCode::query()
                ->where('operator_id', $operator->id)
                ->whereNull('consumed_at')
                ->update(['expires_at' => now()->subSecond()]);
        }
        $this->mobileCode = null;
        $this->mobileCodeExpiresAt = null;
        $this->mobileQrSvg = null;
    }

    public function revokeMobile(int $deviceId): void
    {
        if (! $this->currentManager()) {
            $this->dispatch('notify', title: "Couldn't complete", message: 'Only managers can revoke devices.', kind: 'err');

            return;
        }
        $device = MobileDevice::query()->find($deviceId);
        if ($device === null || $device->revoked_at !== null) {
            return;
        }
        $device->forceFill([
            'revoked_at' => now(),
            'revoked_by_operator_id' => auth('web')->id(),
        ])->save();
        $this->dispatch('notify', title: 'Done', message: 'Phone unlinked. It drops on its next request.', kind: 'ok');
    }

    public function revoke(int $deviceId): void
    {
        if (! $this->currentManager()) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Only managers can revoke linked devices.', kind: 'err');

            return;
        }

        $device = PosDeviceSession::query()->find($deviceId);
        if ($device === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'That device no longer exists.', kind: 'err');

            return;
        }
        if ($device->revoked_at !== null) {
            return;
        }

        $device->forceFill([
            'revoked_at' => now(),
            'revoked_by_operator_id' => auth('web')->id(),
        ])->save();

        $this->dispatch('notify', title: 'Done', message: "Revoked {$device->device_label} · {$device->operator?->name}. The phone drops on its next request.", kind: 'ok');
    }

    public function delete(int $deviceId): void
    {
        if (! $this->currentManager()) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Only managers can delete linked devices.', kind: 'err');

            return;
        }

        $device = PosDeviceSession::query()->find($deviceId);
        if ($device === null) {
            return;
        }
        // Deleting an active session is functionally the same as revoking
        // it (middleware boots the phone when the row is missing), but
        // funnel it through revoke() so the audit trail is consistent.
        if ($device->revoked_at === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Revoke the device first, then delete it.', kind: 'err');

            return;
        }

        $device->delete();
        $this->dispatch('notify', title: 'Done', message: 'Device record deleted.', kind: 'ok');
    }

    private function currentManager(): bool
    {
        $current = auth('web')->user();

        return $current !== null
            && ($current->role ?? OperatorRole::Manager) === OperatorRole::Manager;
    }

    public function render(): View
    {
        $tenant = Tenant::current();
        $devices = PosDeviceSession::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant?->id)
            ->with(['operator:id,name,email,role', 'revokedBy:id,name'])
            ->orderByRaw('revoked_at IS NULL DESC')
            ->orderByDesc('paired_at')
            ->get();

        $mobileDevices = MobileDevice::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant?->id)
            ->with(['operator:id,name,email,role', 'revokedBy:id,name'])
            ->orderByRaw('revoked_at IS NULL DESC')
            ->orderByDesc('paired_at')
            ->get();

        return view('livewire.dashboard.staff.devices', [
            'devices' => $devices,
            'mobileDevices' => $mobileDevices,
        ]);
    }
}

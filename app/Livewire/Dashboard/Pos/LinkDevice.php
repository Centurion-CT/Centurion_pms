<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Pos;

use App\Livewire\Concerns\UsesPosKioskLayout;
use App\Models\PosPairingCode;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * QR-based device pairing surface, the desktop-side of "link a phone".
 *
 * Flow:
 *  - mount(): mint a fresh {@see PosPairingCode} row, hold the plaintext
 *    nonce only long enough to render it into the SVG URL (never stored).
 *  - render(): draw the QR inline as SVG (no JS, no external service).
 *  - wire:poll drives {@see pollClaim()} which flips `$claimed` when the
 *    phone hits /pos/pair/{code} and the DB row picks up claimed_at.
 *  - After claim, the phone has its own session; the desktop just shows
 *    a confirmation and a "link another" button.
 *
 * Old un-claimed codes for this operator are cleaned up on each mount so
 * the table doesn't fill with abandoned pairings after page reloads.
 */
class LinkDevice extends Component
{
    use UsesPosKioskLayout;

    public int $pairingId = 0;

    public string $pairingUrl = '';

    /** Seconds remaining until the current code expires. Ticks down via poll. */
    public int $expiresIn = 0;

    public bool $claimed = false;

    public ?string $claimedDeviceLabel = null;

    public function mount(): void
    {
        $this->createFreshPairing();
    }

    public function regenerate(): void
    {
        $this->createFreshPairing();
    }

    public function pollClaim(): void
    {
        $row = PosPairingCode::query()->find($this->pairingId);
        if ($row === null) {
            $this->createFreshPairing();

            return;
        }

        if ($row->claimed_at !== null) {
            $this->claimed = true;
            $device = $row->deviceSession()->first();
            $this->claimedDeviceLabel = $device === null ? 'a phone' : $device->device_label;

            return;
        }

        $this->expiresIn = max(0, (int) round(now()->diffInSeconds($row->expires_at, false)));
    }

    private function createFreshPairing(): void
    {
        $operatorId = (int) auth('web')->id();

        // Purge any of THIS operator's stale unclaimed codes, one paired
        // phone at a time from the "Link a phone" page is enough; the
        // admin lists any that are already active.
        PosPairingCode::query()
            ->where('operator_id', $operatorId)
            ->whereNull('claimed_at')
            ->delete();

        $code = Str::random(32);
        $row = PosPairingCode::query()->create([
            'operator_id' => $operatorId,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addSeconds(90),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $this->pairingId = $row->id;
        $this->claimed = false;
        $this->claimedDeviceLabel = null;

        $host = request()->getSchemeAndHttpHost();
        $this->pairingUrl = "{$host}/pos/pair/{$code}";
        $this->expiresIn = 90;
    }

    public function render(): View
    {
        $qrSvg = '';
        if (! $this->claimed && $this->pairingUrl !== '' && $this->expiresIn > 0) {
            $result = (new Builder(
                writer: new SvgWriter,
                data: $this->pairingUrl,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: 256,
                margin: 8,
            ))->build();
            $qrSvg = $result->getString();
        }

        $view = view('livewire.dashboard.pos.link-device', [
            'qrSvg' => $qrSvg,
        ]);

        return $this->applyKioskLayout($view);
    }
}

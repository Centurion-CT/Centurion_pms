<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\Tenant;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams a printable QR PNG for a room's in-guest-portal URL.
 * Operators typically download → print → laminate → hang in the
 * room. The image is generated on the fly (cheap) so we always
 * emit the current qr_slug — no cache to invalidate when a room
 * is decommissioned + re-slugged.
 *
 * Not cached on the CDN edge because the image encodes tenant-
 * scoped context; served with `no-store` so a shared print server
 * doesn't accidentally reuse a stale copy across properties.
 */
class RoomQrCodeController extends Controller
{
    public function __invoke(Room $room): Response
    {
        // Room::query is tenant-scoped globally, so a cross-tenant id
        // 404s upstream. Belt-and-braces the property scope too so a
        // multi-branch org can't accidentally print a Lagos QR from
        // an Abuja page.
        $tenant = Tenant::current();
        if ($tenant === null || $room->tenant_id !== $tenant->getKey()) {
            throw new NotFoundHttpException;
        }

        $host = $tenant->primaryHost();
        $target = 'https://'.$host.'/room/'.$room->qr_slug;

        // Endroid v6 API — options are passed to a single build() call
        // (the older fluent builder was removed in the major bump).
        $result = (new Builder())->build(
            writer: new PngWriter(),
            data: $target,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 600,
            margin: 24,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        $filename = 'room-'.$room->room_number.'-portal.png';

        return response($result->getString(), 200)
            ->header('Content-Type', $result->getMimeType())
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"')
            ->header('Cache-Control', 'no-store, private');
    }
}

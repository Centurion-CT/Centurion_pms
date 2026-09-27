<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Tenant;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One printable QR per property — encodes the /portal/{qr_slug}
 * URL. Operators typically print + laminate + hang it in the lobby
 * / on room-service menus. The image is generated on the fly so a
 * property re-slug always emits the fresh URL (no CDN cache to
 * invalidate).
 *
 * Replaces the per-room QR endpoint that shipped in the earlier
 * portal batch — that route (/dashboard/rooms/{room}/qr.png) still
 * exists for backwards compat but the Rooms index no longer links
 * to it.
 */
class PropertyQrCodeController extends Controller
{
    public function __invoke(Property $property): Response
    {
        $tenant = Tenant::current();
        if ($tenant === null || $property->tenant_id !== $tenant->getKey()) {
            throw new NotFoundHttpException;
        }

        $host = $tenant->primaryHost();
        $target = 'https://'.$host.'/portal/'.$property->portal_qr_slug;

        // Endroid v6 — options passed to a single build() call.
        $result = (new Builder())->build(
            writer: new PngWriter(),
            data: $target,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 720,
            margin: 28,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        // Slug (not id) in the filename so the operator picking up the
        // downloaded file knows which branch it's for.
        $filename = 'branch-'.($property->slug ?: (string) $property->id).'-portal.png';

        return response($result->getString(), 200)
            ->header('Content-Type', $result->getMimeType())
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"')
            ->header('Cache-Control', 'no-store, private');
    }
}

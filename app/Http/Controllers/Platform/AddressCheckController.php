<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Platform\BusinessAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /address-check?slug=grandpalace. Backs the home page's address field:
 * whether {slug}.centurionpms.com is free, and what to show if it isn't.
 */
class AddressCheckController extends Controller
{
    public function __invoke(Request $request, BusinessAddress $address): JsonResponse
    {
        return response()->json($address->check((string) $request->query('slug', '')));
    }
}

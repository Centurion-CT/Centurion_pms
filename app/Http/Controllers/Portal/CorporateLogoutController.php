<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CorporateLogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        auth()->guard('corporate')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('corporate.login');
    }
}

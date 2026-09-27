<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\PosDeviceSession;
use App\Models\PosPairingCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Handles the phone side of the QR pairing flow: `/pos/pair/{code}` on
 * the tenant subdomain. Public — no auth. The signed-in operator on
 * the desktop kiosk generated the code; when the phone hits this URL
 * we log the operator in on this browser and stamp the session with a
 * pos_device_session_id so the EnsureLinkedDeviceActive middleware can
 * boot the phone whenever a manager revokes the pairing.
 */
class PosDevicePairingController extends Controller
{
    public function claim(Request $request, string $code): RedirectResponse|Response
    {
        // Normalise before hashing so a stray trailing slash or upper-case
        // paste from a scanner still matches the row.
        $code = trim($code);
        if ($code === '' || strlen($code) > 128) {
            return $this->invalidView('invalid');
        }

        $hash = hash('sha256', $code);
        $pairing = PosPairingCode::query()->where('code_hash', $hash)->first();

        if ($pairing === null) {
            return $this->invalidView('invalid');
        }
        if ($pairing->isClaimed()) {
            return $this->invalidView('already_used');
        }
        if ($pairing->isExpired()) {
            return $this->invalidView('expired');
        }

        try {
            $deviceSession = DB::transaction(function () use ($pairing, $request) {
                $locked = PosPairingCode::query()->lockForUpdate()->find($pairing->id);
                if ($locked === null) {
                    throw new RuntimeException('invalid');
                }
                if ($locked->claimed_at !== null) {
                    throw new RuntimeException('already_used');
                }
                if ($locked->expires_at->isPast()) {
                    throw new RuntimeException('expired');
                }

                $session = PosDeviceSession::query()->create([
                    'operator_id' => $locked->operator_id,
                    'token_hash' => hash('sha256', Str::random(64)),
                    'device_label' => self::guessDeviceLabel($request->userAgent()),
                    'user_agent' => $this->truncate($request->userAgent(), 512),
                    'paired_ip' => $request->ip(),
                    'paired_at' => now(),
                    'last_seen_at' => now(),
                ]);

                $locked->update([
                    'claimed_at' => now(),
                    'device_session_id' => $session->id,
                ]);

                return $session;
            });
        } catch (RuntimeException $e) {
            return $this->invalidView($e->getMessage());
        }

        $operator = Operator::query()->findOrFail($deviceSession->operator_id);
        Auth::guard('web')->login($operator);
        $request->session()->regenerate();
        $request->session()->put('pos_device_session_id', $deviceSession->id);

        return redirect()->route('tenant.pos.pick-outlet');
    }

    private function invalidView(string $reason): Response
    {
        $status = $reason === 'invalid' ? 404 : 410;

        return response()->view('errors.pos-pair-invalid', ['reason' => $reason], $status);
    }

    /**
     * Cheap UA sniff so managers see something better than a 300-char
     * raw UA string in the linked-devices table. Not perfect — the
     * operator can rename the device from the admin page later.
     */
    private static function guessDeviceLabel(?string $userAgent): string
    {
        $ua = $userAgent ?? '';
        if ($ua === '') {
            return 'Unknown device';
        }

        $platform = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown',
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        return "{$platform} · {$browser}";
    }

    private function truncate(?string $s, int $max): ?string
    {
        if ($s === null) {
            return null;
        }

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }
}

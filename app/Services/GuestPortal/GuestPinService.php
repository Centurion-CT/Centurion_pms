<?php

declare(strict_types=1);

namespace App\Services\GuestPortal;

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Random\Randomizer;

/**
 * Cross-Keverna guest PIN — one PIN per phone number, works at
 * every Keverna property. Encapsulates the phone-normalise +
 * hash-lookup logic so the RoomPortal component doesn't have to
 * know about the legacy per-property table.
 *
 * Read path (in order):
 *   1. guest_global_pins where phone_hash matches → use this
 *   2. legacy guest_room_pins for (customer_id, property_id) →
 *      use this AND migrate on next successful verify (write
 *      through to global)
 *   3. no PIN → prompt guest to set one
 *
 * Write path: always write to guest_global_pins. Legacy per-
 * property rows are left alone (read fallback only, never new
 * writes) and can be dropped once nobody's reading them.
 */
class GuestPinService
{
    /**
     * True if this phone/customer combo has a PIN somewhere we can
     * verify against — global first, then per-property fallback.
     */
    public function hasPinFor(string $phone, Customer $customer, Property $property): bool
    {
        if ($this->hasGlobalPin($phone)) {
            return true;
        }
        return $customer->hasPinFor($property);
    }

    /**
     * Verify a candidate PIN. Prefers the global PIN when present;
     * if the guest still only has a legacy per-property PIN and it
     * verifies, we mirror it to the global table so their next visit
     * anywhere on Keverna hits the fast path.
     */
    public function verify(string $phone, string $pin, Customer $customer, Property $property): bool
    {
        // Try global first — the common path for anyone who's set
        // up their PIN under the new scheme.
        $global = $this->fetchGlobal($phone);
        if ($global !== null) {
            if ($this->isLocked($global)) {
                return false;
            }
            $ok = Hash::check($pin, (string) $global->pin_hash);
            $this->recordAttempt($phone, $ok);
            return $ok;
        }

        // Legacy fallback — verify against the per-property table,
        // and if it matches, mirror the PIN to the global table so
        // the next scan (anywhere) uses the global path.
        if ($customer->verifyPinFor($property, $pin)) {
            $this->set($phone, $pin);
            return true;
        }
        return false;
    }

    /**
     * Store a fresh PIN globally (writes bcrypt hash). Called on
     * first-time-setup AND during the legacy-to-global mirror on
     * successful verify.
     */
    public function set(string $phone, string $pin): void
    {
        $hash = $this->phoneHash($phone);
        if ($hash === null) {
            return;
        }
        DB::table('guest_global_pins')->updateOrInsert(
            ['phone_hash' => $hash],
            [
                'pin_hash' => Hash::make($pin),
                'recent_failures' => 0,
                'locked_until' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * True if there's ANY global PIN for this phone (regardless of
     * lock state) — used to decide "set new" vs "enter existing"
     * in the UI.
     */
    public function hasGlobalPin(string $phone): bool
    {
        $hash = $this->phoneHash($phone);
        if ($hash === null) {
            return false;
        }
        return DB::table('guest_global_pins')->where('phone_hash', $hash)->exists();
    }

    private function fetchGlobal(string $phone): ?object
    {
        $hash = $this->phoneHash($phone);
        if ($hash === null) {
            return null;
        }
        return DB::table('guest_global_pins')->where('phone_hash', $hash)->first();
    }

    private function isLocked(object $row): bool
    {
        if ($row->locked_until === null) {
            return false;
        }
        return Carbon::parse($row->locked_until)->isFuture();
    }

    /**
     * Track wrong PINs; auto-lock the row for 15 minutes once we
     * reach 5 consecutive failures. Cleared on any successful
     * verify.
     */
    private function recordAttempt(string $phone, bool $ok): void
    {
        $hash = $this->phoneHash($phone);
        if ($hash === null) {
            return;
        }
        if ($ok) {
            DB::table('guest_global_pins')
                ->where('phone_hash', $hash)
                ->update(['recent_failures' => 0, 'locked_until' => null, 'updated_at' => now()]);
            return;
        }
        $row = DB::table('guest_global_pins')->where('phone_hash', $hash)->first();
        if ($row === null) return;
        $failures = ((int) $row->recent_failures) + 1;
        $lockUntil = $failures >= 5 ? now()->addMinutes(15) : null;
        DB::table('guest_global_pins')
            ->where('phone_hash', $hash)
            ->update([
                'recent_failures' => $failures,
                'locked_until' => $lockUntil,
                'updated_at' => now(),
            ]);
    }

    /**
     * Normalise a Nigerian phone to the last-9-digit local form
     * before hashing — so +2348012345678 / 2348012345678 /
     * 08012345678 all hash to the same key. Uses sha256 with a
     * per-app pepper so a raw phone number can't be checked
     * against the hash by knowing the algorithm.
     */
    private function phoneHash(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') return null;
        $tail = substr($digits, -9);
        if (strlen($tail) < 9) return null;
        $pepper = (string) config('app.key');
        return hash('sha256', $pepper.':guest_pin:'.$tail);
    }

    // ── PIN reset OTP ──────────────────────────────────────────
    // 6-digit code sent to the guest's WhatsApp (via Sendpipe) when
    // they've forgotten their PIN. Cache-backed to keep it simple:
    // one active code per phone_hash at a time, 10-min TTL, 5-strike
    // lockout mirroring the main PIN entry. On successful verify the
    // code + strike counter are cleared so the caller can now set a
    // fresh PIN.

    private const RESET_TTL_SECONDS = 600;      // 10 minutes
    private const RESET_MAX_ATTEMPTS = 5;

    /**
     * Generate a 6-digit reset code, cache-store its hash for 10min,
     * and return the plaintext so the caller can dispatch it via
     * GuestNotifier. Returns null when the phone can't be normalised
     * (guest typed nonsense).
     */
    public function issueResetCode(string $phone): ?string
    {
        $hash = $this->phoneHash($phone);
        if ($hash === null) return null;

        // Random 6-digit, zero-padded so "042713" isn't collapsed.
        $code = str_pad((string) (new Randomizer)->getInt(0, 999_999), 6, '0', STR_PAD_LEFT);
        Cache::put(
            $this->resetKey($hash),
            ['code_hash' => Hash::make($code), 'attempts' => 0, 'created_at' => Carbon::now()->toIso8601String()],
            self::RESET_TTL_SECONDS,
        );
        return $code;
    }

    /**
     * Verify a submitted reset code. Returns true on match; on
     * failure bumps a strike counter and locks the reset flow after
     * 5 wrong tries (the row is dropped, guest has to re-request).
     */
    public function verifyResetCode(string $phone, string $submitted): bool
    {
        $hash = $this->phoneHash($phone);
        if ($hash === null) return false;
        $key = $this->resetKey($hash);
        $row = Cache::get($key);
        if (! is_array($row)) return false;

        if ((int) ($row['attempts'] ?? 0) >= self::RESET_MAX_ATTEMPTS) {
            Cache::forget($key);
            return false;
        }

        $ok = Hash::check($submitted, (string) $row['code_hash']);
        if (! $ok) {
            $row['attempts'] = (int) ($row['attempts'] ?? 0) + 1;
            Cache::put($key, $row, self::RESET_TTL_SECONDS);
            return false;
        }

        // Success — invalidate the code so it can't be replayed.
        Cache::forget($key);
        return true;
    }

    private function resetKey(string $phoneHash): string
    {
        return 'guest_pin_reset:'.$phoneHash;
    }
}

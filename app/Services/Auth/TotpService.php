<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Str;

/**
 * Time-based one-time password (RFC 6238) implementation.
 *
 * Kept self-contained so we don't drag in another dependency for
 * ~100 lines of well-defined maths. Uses Base32 for the shared
 * secret + SHA-1 HMAC (the default Google Authenticator / Authy
 * expect) with the standard 30-second time step and 6-digit codes.
 *
 * Verification accepts codes from a ±1-step window so a clock
 * skew of up to 30s in either direction doesn't lock the admin
 * out of their own account.
 */
class TotpService
{
    private const DIGITS   = 6;
    private const PERIOD   = 30;
    private const ALGO     = 'sha1';
    private const WINDOW   = 1;

    /**
     * Fresh 16-character Base32 secret. Long enough for 80 bits of
     * entropy, short enough to key by hand if the QR fails.
     */
    public function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        $bytes = random_bytes(10); // 80 bits
        foreach (str_split($bytes) as $b) {
            $out .= $alphabet[ord($b) % 32];
            $out .= $alphabet[(ord($b) >> 3) % 32];
        }
        return substr($out, 0, 16);
    }

    /**
     * otpauth://totp/{issuer}:{label}?secret=…&issuer=…&digits=6&period=30
     * URI. Pass to any QR encoder + the admin's authenticator app
     * decodes the secret + issuer in one scan.
     */
    public function otpauthUri(string $secret, string $issuer, string $label): string
    {
        $params = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => strtoupper(self::ALGO),
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($issuer),
            rawurlencode($label),
            $params,
        );
    }

    /**
     * Constant-time verify of a 6-digit code against the secret at
     * "now" ±1 period. Returns false for anything else - malformed
     * input, wrong length, timing outside the window.
     */
    public function verify(string $secret, string $code, ?int $atTimestamp = null): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) return false;

        $now = (int) floor(($atTimestamp ?? time()) / self::PERIOD);
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $candidate = $this->computeCode($secret, $now + $offset);
            if (hash_equals($candidate, $code)) return true;
        }
        return false;
    }

    /**
     * Ten fresh recovery codes. Format XXXX-XXXX in upper-case
     * base32-alphabet chars so they're short + easy to read from a
     * printed sheet without ambiguity (no 0/O, 1/I).
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 10): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $left  = ''; $right = '';
            for ($j = 0; $j < 4; $j++) {
                $left  .= $alphabet[random_int(0, 31)];
                $right .= $alphabet[random_int(0, 31)];
            }
            $codes[] = $left.'-'.$right;
        }
        return $codes;
    }

    /**
     * Standard TOTP compute step - HMAC(secret, counter), truncate
     * per RFC 4226, mod 10^digits.
     */
    private function computeCode(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binary = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac(self::ALGO, $binary, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
             (ord($hash[$offset + 3]) & 0xFF)
        );
        $modulo = 10 ** self::DIGITS;
        return str_pad((string) ($truncated % $modulo), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32 = strtoupper(rtrim($base32, '='));
        $buffer = 0; $bits = 0; $out = '';
        foreach (str_split($base32) as $char) {
            $val = strpos($alphabet, $char);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $out;
    }
}

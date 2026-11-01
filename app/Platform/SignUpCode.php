<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * The 6-digit code that confirms a sign-up's email before the business is
 * created. Nothing exists in the database yet at that point, so the code
 * lives in the cache, hashed, keyed by the email address.
 *
 * A code lasts 15 minutes and allows 5 wrong tries. A new one can be sent
 * once a minute.
 */
class SignUpCode
{
    public const TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    /** Cache key prefix; subclasses use their own so codes never cross. */
    protected const PREFIX = 'signup-code:';

    /** Issue a fresh code for this email. Returns the plain code to send. */
    public function issue(string $email): string
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($email), [
            'hash' => Hash::make($code),
            'attempts' => 0,
            'sent_at' => now()->timestamp,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $code;
    }

    /** Seconds until another code may be sent; 0 when it can be sent now. */
    public function waitBeforeResend(string $email): int
    {
        $entry = Cache::get($this->key($email));
        if ($entry === null) {
            return 0;
        }

        return max(0, self::RESEND_SECONDS - (now()->timestamp - (int) $entry['sent_at']));
    }

    /**
     * @return 'ok'|'wrong'|'expired'|'locked'
     */
    public function verify(string $email, string $code): string
    {
        $key = $this->key($email);
        $entry = Cache::get($key);

        if ($entry === null) {
            return 'expired';
        }
        if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
            return 'locked';
        }
        if (! Hash::check($code, $entry['hash'])) {
            $entry['attempts']++;
            Cache::put($key, $entry, now()->addMinutes(self::TTL_MINUTES));

            return $entry['attempts'] >= self::MAX_ATTEMPTS ? 'locked' : 'wrong';
        }

        Cache::forget($key);

        return 'ok';
    }

    private function key(string $email): string
    {
        return static::PREFIX.sha1(strtolower(trim($email)));
    }
}

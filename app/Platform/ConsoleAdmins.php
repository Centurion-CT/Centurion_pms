<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * Who may use the console: the email addresses in CONSOLE_ADMINS
 * (config keverna.console_admins). There is no admin account table; an
 * admin signs in with a code sent to one of these addresses, and
 * removing an address ends that admin's access on their next request.
 */
final class ConsoleAdmins
{
    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_filter(array_map(
            fn (string $e) => strtolower(trim($e)),
            explode(',', (string) config('keverna.console_admins')),
        )));
    }

    public static function allows(?string $email): bool
    {
        return $email !== null && in_array(strtolower(trim($email)), self::all(), true);
    }
}

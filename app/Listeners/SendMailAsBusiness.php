<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Tenant;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Emails sent for a business carry its name as the sender
 * ("Sunrise Court <noreply@…>"); the rest carry the software's name
 * (docs/MULTITENANCY.md, step 6). Only the default sender is renamed: a
 * mailable that sets its own `from` keeps it. Booking and corporate
 * mails bind their business before they send, so this sees it.
 */
class SendMailAsBusiness
{
    public function handle(MessageSending $event): void
    {
        $default = (string) config('mail.from.address');
        $from = $event->message->getFrom()[0] ?? null;
        if ($from === null || strcasecmp($from->getAddress(), $default) !== 0) {
            return;
        }

        $name = Tenant::current()?->name ?? (string) config('brand.name');
        $event->message->from(new Address($from->getAddress(), $name));
    }
}

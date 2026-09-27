<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerInteractionType: string
{
    case Note     = 'note';
    case Call     = 'call';
    case Email    = 'email';
    case Whatsapp = 'whatsapp';
    case Sms      = 'sms';
    case InPerson = 'in_person';
    case System   = 'system';     // auto-generated booking lifecycle events

    public function label(): string
    {
        return match ($this) {
            self::Note     => 'Note',
            self::Call     => 'Call',
            self::Email    => 'Email',
            self::Whatsapp => 'WhatsApp',
            self::Sms      => 'SMS',
            self::InPerson => 'In person',
            self::System   => 'System',
        };
    }
}

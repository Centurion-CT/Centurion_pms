<?php

declare(strict_types=1);

namespace App\Services\Email;

/**
 * Resolved subject + body for a templated email. `isCustom` lets
 * the caller (and the operator-facing preview) distinguish "I'm
 * looking at the default" from "I'm looking at the customised
 * version".
 */
final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $body,
        public bool $isCustom,
    ) {}
}

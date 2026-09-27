<?php

declare(strict_types=1);

namespace App\Services\Dokwe;

/**
 * Value object mirroring the rows dokwe's domains table returns. Built from
 * the JSON DokweClient receives — never instantiated by application code
 * directly. Immutable on purpose: poll loops should issue fresh fetches
 * rather than mutating a previously returned object.
 */
final class DokweDomain
{
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $domain,
        public readonly string $type,
        public readonly bool $verified,
        public readonly string $sslStatus,
        public readonly ?string $sslError,
    ) {}

    /**
     * Build from the raw associative array dokwe returns. Defensive about
     * missing fields so a backwards-incompatible API change surfaces as a
     * domain error rather than a TypeError.
     *
     * @param  array<string,mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (string) ($row['id'] ?? ''),
            projectId: (string) ($row['projectId'] ?? ''),
            domain: (string) ($row['domain'] ?? ''),
            type: (string) ($row['type'] ?? ''),
            verified: (bool) ($row['verified'] ?? false),
            sslStatus: (string) ($row['sslStatus'] ?? 'unknown'),
            sslError: isset($row['sslError']) ? (string) $row['sslError'] : null,
        );
    }

    /**
     * The two-step terminal happy path: CNAME is right AND ACME succeeded.
     * Tenants whose domain returns `isProvisioned() === true` can be flipped
     * from "domain pending" to "live" on the tenant onboarding UI.
     */
    public function isProvisioned(): bool
    {
        return $this->verified && $this->sslStatus === 'provisioned';
    }

    public function isFailed(): bool
    {
        return $this->sslStatus === 'failed' || ($this->sslError !== null && $this->sslError !== '');
    }
}

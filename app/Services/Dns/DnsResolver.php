<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Minimal DNS lookup surface used by custom domain verification (PRD §6.6.2).
 *
 * Two implementations:
 *  - SystemDnsResolver: production, wraps PHP's dns_get_record().
 *  - FakeDnsResolver:   tests, manual record registration.
 *
 * Bound in AppServiceProvider; tests override the binding via
 * $this->app->instance(DnsResolver::class, $fake).
 */
interface DnsResolver
{
    /**
     * Returns the resolved CNAME target for the given hostname, or null if
     * the hostname does not have a CNAME record.
     */
    public function cname(string $hostname): ?string;

    /**
     * Returns the list of TXT record string values for the given hostname.
     * Empty array when no TXT records exist.
     *
     * @return list<string>
     */
    public function txt(string $hostname): array;
}

<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Real DNS lookups via PHP's dns_get_record(). Used in staging + production.
 * Tests substitute FakeDnsResolver via container override.
 */
class SystemDnsResolver implements DnsResolver
{
    public function cname(string $hostname): ?string
    {
        $records = @dns_get_record($hostname, DNS_CNAME);
        if (! is_array($records) || $records === []) {
            return null;
        }

        foreach ($records as $r) {
            if (($r['type'] ?? null) === 'CNAME' && isset($r['target'])) {
                return rtrim((string) $r['target'], '.');
            }
        }

        return null;
    }

    public function txt(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_TXT);
        if (! is_array($records) || $records === []) {
            return [];
        }

        $values = [];
        foreach ($records as $r) {
            if (($r['type'] ?? null) === 'TXT' && isset($r['txt'])) {
                $values[] = (string) $r['txt'];
            }
        }

        return $values;
    }
}

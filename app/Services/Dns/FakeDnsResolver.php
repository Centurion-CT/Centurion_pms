<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * In-memory DNS resolver for tests. Register a CNAME target or TXT values for
 * a hostname, then assertions hit those rather than real network DNS.
 */
class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, string> */
    private array $cnames = [];

    /** @var array<string, list<string>> */
    private array $txts = [];

    public function withCname(string $hostname, string $target): self
    {
        $this->cnames[strtolower($hostname)] = $target;

        return $this;
    }

    /**
     * @param  list<string>  $values
     */
    public function withTxt(string $hostname, array $values): self
    {
        $this->txts[strtolower($hostname)] = $values;

        return $this;
    }

    public function cname(string $hostname): ?string
    {
        return $this->cnames[strtolower($hostname)] ?? null;
    }

    public function txt(string $hostname): array
    {
        return $this->txts[strtolower($hostname)] ?? [];
    }
}

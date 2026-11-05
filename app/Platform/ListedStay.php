<?php

declare(strict_types=1);

namespace App\Platform;

/** One building on the Stays marketplace. */
final class ListedStay
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $propertyId,
        public readonly string $building,
        public readonly string $business,
        public readonly string $city,
        public readonly string $slug,
        public readonly string $host,
        public readonly ?string $photo,
        public readonly int $fromPerNight,
        public readonly float $rating,
        public readonly int $reviews,
        public readonly int $free = 0,
        public readonly ?int $total = null,
    ) {}

    /** The building on the business's own site, carrying the guest's search. */
    public function url(array $query): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return $scheme.'://'.$this->host.($port ? ':'.$port : '').'/apartments?'.http_build_query(['where' => [$this->slug]] + $query);
    }

    public function withAvailability(int $free, ?int $total): self
    {
        return new self(
            $this->tenantId, $this->propertyId, $this->building, $this->business, $this->city, $this->slug,
            $this->host, $this->photo, $this->fromPerNight, $this->rating, $this->reviews, $free, $total,
        );
    }
}

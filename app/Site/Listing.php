<?php

declare(strict_types=1);

namespace App\Site;

use App\Models\Property;
use App\Models\RoomCategory;

/** One apartment type on the booking site, for a given stay. */
final class Listing
{
    /**
     * @param  array<string, string>  $strip  Y-m-d => free | last | gone, from check-in
     */
    public function __construct(
        public readonly RoomCategory $type,
        public readonly Property $branch,
        public readonly int $freeForStay,
        public readonly array $strip,
        public readonly ?Quote $quote,
    ) {}

    public function photo(): ?string
    {
        $media = $this->type->getFirstMedia('gallery') ?? $this->branch->getFirstMedia('hero') ?? $this->branch->getFirstMedia('gallery');

        if ($media === null) {
            return null;
        }

        return $media->hasGeneratedConversion('medium') ? $media->getUrl('medium') : $media->getUrl();
    }

    public function sleeps(): int
    {
        return (int) $this->type->capacity_adults + (int) $this->type->capacity_children;
    }
}

<?php

declare(strict_types=1);

namespace App\Site;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** A photo's resized copy, or the original until that copy has been made. */
final class Photo
{
    public static function url(Media $media, string $conversion = 'medium'): string
    {
        return $media->hasGeneratedConversion($conversion) ? $media->getUrl($conversion) : $media->getUrl();
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Branding;

use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Property-level photo manager (PRD §10 / §16):
 *
 *   - Logo (single, PNG/SVG), top-left of landing nav + mail header
 *   - Gallery (up to 10), auto-rotating hero carousel on the landing.
 *     One tile in the gallery can be marked `is_hero=true`; that tile
 *     leads the carousel and doubles as the single-image fallback for
 *     surfaces that only render one property image (email hero, etc.).
 *
 * Prior to 2026-07-22 the gallery and the "hero fallback" lived in
 * separate media collections (`gallery` + `hero`) with three separate
 * upload forms. Migration 2026_07_22_100000_migrate_property_hero_to_gallery
 * collapsed them; this component and its blade are the UI counterpart.
 *
 * Uploads pass through Spatie's `addMedia()` pipeline, same convention
 * as Rooms\MediaManager and RoomCategories\Form. TenantPathGenerator
 * scopes files under tenant_{id}/.
 */
#[Layout('layouts.tenant-auth')]
class Photos extends Component
{
    use WithFileUploads;

    private const GALLERY_MAX = 10;

    /** @var TemporaryUploadedFile|null */
    #[Validate(['logo' => 'nullable|image|mimes:jpg,jpeg,png,webp,svg|max:2048'])]
    public $logo = null;

    /** @var list<TemporaryUploadedFile> */
    #[Validate(['gallery.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120'])]
    public array $gallery = [];

    public function uploadLogo(): void
    {
        $this->validate(['logo' => 'required|image|mimes:jpg,jpeg,png,webp,svg|max:2048']);

        /** @var UploadedFile $file */
        $file = $this->logo;
        $this->property()
            ->addMedia($file->getRealPath())
            ->usingFileName($file->getClientOriginalName())
            ->toMediaCollection('logo');

        $this->reset('logo');
        session()->flash('photos-status', __('Logo updated.'));
    }

    /**
     * Called automatically from JS after Livewire finishes staging the
     * files (see resources/js/photo-manager.js). No button click required.
     */
    public function uploadGallery(): void
    {
        if (empty($this->gallery)) {
            return;
        }
        $this->validate();

        $property = $this->property();
        $remaining = max(0, self::GALLERY_MAX - $property->getMedia('gallery')->count());

        foreach (array_slice($this->gallery, 0, $remaining) as $file) {
            /** @var UploadedFile $file */
            $property
                ->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->toMediaCollection('gallery');
        }

        $this->reset('gallery');
        session()->flash('photos-status', __('Photos uploaded.'));
    }

    public function deleteLogo(): void
    {
        $this->property()->clearMediaCollection('logo');
        session()->flash('photos-status', __('Logo removed.'));
    }

    /**
     * Delete one gallery photo, invoked by the per-tile × button in the
     * shared photo-manager component.
     */
    public function deleteGalleryPhoto(int $mediaId): void
    {
        $media = $this->property()->getMedia('gallery')->firstWhere('id', $mediaId);
        $media?->delete();
    }

    /**
     * Bulk delete, invoked by the "Delete selected" button in the
     * shared photo-manager component's floating bulk-actions bar.
     *
     * @param  list<int>  $ids
     */
    public function deleteGalleryPhotos(array $ids): void
    {
        $property = $this->property();
        $allowed = $property->getMedia('gallery')->pluck('id')->all();
        foreach ($ids as $id) {
            $id = (int) $id;
            if (! in_array($id, $allowed, true)) {
                continue;
            }
            $media = $property->getMedia('gallery')->firstWhere('id', $id);
            $media?->delete();
        }
    }

    /**
     * Persist a drag-reorder. Filters the incoming ids through the
     * property's own gallery so a spoofed id from another tenant can't
     * slip into Media::setNewOrder(). Same guard pattern as
     * Rooms\MediaManager::reorder().
     *
     * @param  list<int>  $orderedIds
     */
    public function reorderGallery(array $orderedIds): void
    {
        $property = $this->property();
        $allowed = $property->getMedia('gallery')->pluck('id')->all();
        $clean = array_values(array_filter(
            $orderedIds,
            fn ($id) => in_array((int) $id, $allowed, true),
        ));
        Media::setNewOrder($clean);
    }

    /**
     * Mark a gallery tile as the hero. Only one tile can hold `is_hero`
     * at a time, flipping one on clears the flag on every other tile.
     */
    public function setGalleryHero(int $mediaId): void
    {
        $property = $this->property();
        foreach ($property->getMedia('gallery') as $m) {
            $shouldBeHero = $m->id === $mediaId;
            if ((bool) $m->getCustomProperty('is_hero') !== $shouldBeHero) {
                $m->setCustomProperty('is_hero', $shouldBeHero)->save();
            }
        }
    }

    public function render(): View
    {
        $property = $this->property();

        return view('livewire.dashboard.branding.photos', [
            'property'     => $property,
            'logoMedia'    => $property->getFirstMedia('logo'),
            'galleryMedia' => $property->getMedia('gallery'),
            'galleryMax'   => self::GALLERY_MAX,
        ]);
    }

    private function property(): Property
    {
        /** @var Tenant|null $tenant */
        $tenant = Tenant::current();
        // Re-query rather than reading `$tenant->property`, the relation
        // caches its media collection on first access, so a follow-up
        // action (delete, reorder, setHero) would see the pre-upload
        // snapshot and silently no-op. Matches the fresh-fetch pattern
        // used by Rooms\MediaManager::room().
        $property = $tenant?->property()->first();
        if ($property === null) {
            throw new NotFoundHttpException;
        }

        return $property;
    }
}

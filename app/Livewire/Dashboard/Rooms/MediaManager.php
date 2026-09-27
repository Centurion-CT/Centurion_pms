<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Rooms;

use App\Models\Room;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Per-room media manager (PRD §6.3.3): upload, reorder, alt text, mark one
 * image as the gallery hero, delete.
 *
 * Uploads pass through Spatie's `addMedia()` pipeline, which means:
 *  - Mime/size validation runs server-side (config('media-library.max_file_size'))
 *  - Path generation uses TenantPathGenerator (tenant_{id}/ prefix)
 *  - Conversions (thumb/medium/full) queue automatically
 *
 * Route-model-bound on {room}; the BelongsToTenant scope makes foreign-tenant
 * ids 404 before the component even mounts.
 */
#[Layout('layouts.tenant-auth')]
class MediaManager extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $roomId;

    /** @var list<TemporaryUploadedFile> */
    #[Validate([
        'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
    ])]
    public array $photos = [];

    public function mount(Room $room): void
    {
        $this->roomId = $room->id;
    }

    public function upload(): void
    {
        $this->validate();

        $room = $this->room();
        $remaining = max(0, 20 - $room->getMedia('gallery')->count());

        foreach (array_slice($this->photos, 0, $remaining) as $file) {
            /** @var UploadedFile $file */
            $room
                ->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->toMediaCollection('gallery');
        }

        $this->reset('photos');
        session()->flash('status', __('Photos uploaded.'));
    }

    /**
     * Persists the operator's drag-reorder. `$orderedIds` is the new full list
     * of media ids in the desired order.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $room = $this->room();

        // Spatie ships setNewOrder() but we constrain to the room's media set
        // first so a malicious id from another room/tenant can't sneak in.
        $allowed = $room->getMedia('gallery')->pluck('id')->all();
        $clean = array_values(array_filter($orderedIds, fn ($id) => in_array((int) $id, $allowed, true)));

        Media::setNewOrder($clean);
    }

    public function setHero(int $mediaId): void
    {
        $room = $this->room();
        foreach ($room->getMedia('gallery') as $m) {
            $shouldBeHero = $m->id === $mediaId;
            if ((bool) $m->getCustomProperty('is_hero') !== $shouldBeHero) {
                $m->setCustomProperty('is_hero', $shouldBeHero)->save();
            }
        }
    }

    public function setAlt(int $mediaId, string $alt): void
    {
        $media = $this->room()->getMedia('gallery')->firstWhere('id', $mediaId);
        if ($media === null) {
            return;
        }
        $media->setCustomProperty('alt', $alt)->save();
    }

    public function delete(int $mediaId): void
    {
        $media = $this->room()->getMedia('gallery')->firstWhere('id', $mediaId);
        if ($media === null) {
            return;
        }
        $media->delete();
    }

    /**
     * Bulk delete, invoked by the shared photo-manager component's
     * "Delete selected" button. Filters against the room's own gallery
     * so a spoofed id can't reach across tenants.
     *
     * @param  list<int>  $ids
     */
    public function deleteMany(array $ids): void
    {
        $room = $this->room();
        $allowed = $room->getMedia('gallery')->pluck('id')->all();
        foreach ($ids as $id) {
            $id = (int) $id;
            if (! in_array($id, $allowed, true)) {
                continue;
            }
            $media = $room->getMedia('gallery')->firstWhere('id', $id);
            $media?->delete();
        }
    }

    public function render(): View
    {
        return view('livewire.dashboard.rooms.media-manager', [
            'room' => $this->room(),
            'gallery' => $this->room()->getMedia('gallery'),
            'remainingSlots' => max(0, 20 - $this->room()->getMedia('gallery')->count()),
        ]);
    }

    private function room(): Room
    {
        return Room::query()->findOrFail($this->roomId);
    }
}

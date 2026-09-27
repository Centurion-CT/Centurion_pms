<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\RoomCategories;

use App\Enums\DepositType;
use App\Enums\RoomBookingType;
use App\Enums\RoomStatus;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    use WithFileUploads;

    public ?int $categoryId = null;

    // Identity
    public string $name = '';
    public string $type = '';
    public string $description = '';

    // Capacity
    public int $capacity_adults = 2;
    public int $capacity_children = 0;

    // Pricing (NGN)
    public ?int $base_rate = null;
    // Optional second nightly tier for guests / operators picking
    // "with complimentary breakfast". Null = tier disabled, no
    // radio pair shown to the operator or guest.
    public ?int $breakfast_included_rate = null;
    public ?int $monthly_rate = null;
    public int $min_nights = 1;
    public int $weekly_discount_percent = 0;
    public int $monthly_discount_percent = 0;

    // Deposit
    public string $deposit_type = 'none';
    public ?int $deposit_value = null;

    // Booking type + status + rules
    public string $booking_type = 'short_let';
    public string $status = 'active';
    public string $rules = '';

    // Media uploads, appended to the category's gallery on save.
    /** @var array<int, mixed> */
    public array $newPhotos = [];

    /**
     * IDs of reusable housekeeping checklist bundles attached to
     * this category. Managers author bundles + items on the
     * Housekeeping page; here we just pick which ones apply. Every
     * ticked bundle contributes its items to the fresh
     * RoomInspection opened on every checkout (union deduped by
     * case-insensitive label, see OpenRoomInspection).
     *
     * @var array<int, int>
     */
    public array $attachedChecklistIds = [];

    public function mount(?RoomCategory $roomCategory = null): void
    {
        if ($roomCategory && $roomCategory->exists) {
            $this->categoryId = $roomCategory->id;
            $this->name = $roomCategory->name;
            $this->type = $roomCategory->type;
            $this->description = (string) $roomCategory->description;
            $this->capacity_adults = $roomCategory->capacity_adults;
            $this->capacity_children = $roomCategory->capacity_children;
            $this->base_rate = $roomCategory->base_rate;
            $this->breakfast_included_rate = $roomCategory->breakfast_included_rate;
            $this->monthly_rate = $roomCategory->monthly_rate;
            $this->min_nights = $roomCategory->min_nights;
            $this->weekly_discount_percent = $roomCategory->weekly_discount_percent;
            $this->monthly_discount_percent = $roomCategory->monthly_discount_percent;
            $this->deposit_type = $roomCategory->deposit_type->value;
            $this->deposit_value = $roomCategory->deposit_value;
            $this->booking_type = $roomCategory->booking_type->value;
            $this->status = $roomCategory->status->value;
            $this->rules = (string) $roomCategory->rules;

            // Hydrate ids of attached bundles from the pivot.
            $this->attachedChecklistIds = $roomCategory->checklists()
                ->pluck('housekeeping_checklists.id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }
    }

    public function deletePhoto(int $mediaId): void
    {
        if ($this->categoryId === null) {
            return;
        }
        $category = RoomCategory::query()->findOrFail($this->categoryId);
        $media = $category->getMedia('gallery')->firstWhere('id', $mediaId);
        $media?->delete();
    }

    /**
     * Bulk delete, invoked by the shared photo-manager component's
     * "Delete selected" bar.
     *
     * @param  list<int>  $ids
     */
    public function deletePhotos(array $ids): void
    {
        if ($this->categoryId === null) {
            return;
        }
        $category = RoomCategory::query()->findOrFail($this->categoryId);
        $allowed = $category->getMedia('gallery')->pluck('id')->all();
        foreach ($ids as $id) {
            $id = (int) $id;
            if (! in_array($id, $allowed, true)) {
                continue;
            }
            $media = $category->getMedia('gallery')->firstWhere('id', $id);
            $media?->delete();
        }
    }

    /**
     * Persist a drag-reorder from the shared component. Filters through
     * the category's own gallery ids so a spoofed id can't cross tenants
     * (same guard as Rooms\MediaManager::reorder()).
     *
     * @param  list<int>  $orderedIds
     */
    public function reorderPhotos(array $orderedIds): void
    {
        if ($this->categoryId === null) {
            return;
        }
        $category = RoomCategory::query()->findOrFail($this->categoryId);
        $allowed = $category->getMedia('gallery')->pluck('id')->all();
        $clean = array_values(array_filter(
            $orderedIds,
            fn ($id) => in_array((int) $id, $allowed, true),
        ));
        Media::setNewOrder($clean);
    }

    /**
     * Mark one gallery tile as this category's hero (leads the category
     * detail page + inventory grid).
     */
    public function setPhotoHero(int $mediaId): void
    {
        if ($this->categoryId === null) {
            return;
        }
        $category = RoomCategory::query()->findOrFail($this->categoryId);
        foreach ($category->getMedia('gallery') as $m) {
            $shouldBeHero = $m->id === $mediaId;
            if ((bool) $m->getCustomProperty('is_hero') !== $shouldBeHero) {
                $m->setCustomProperty('is_hero', $shouldBeHero)->save();
            }
        }
    }

    /**
     * Drop a still-pending (not-yet-saved) upload from the queue. The
     * file lives only in Livewire's temp storage at this point, the
     * temp janitor cron clears it later, but we reset the slot here so
     * the operator sees an immediate UI response.
     */
    public function removePending(int $index): void
    {
        if (! isset($this->newPhotos[$index])) {
            return;
        }
        unset($this->newPhotos[$index]);
        // Re-key so subsequent indexes stay contiguous (the view
        // uses $i as the wire:click argument).
        $this->newPhotos = array_values($this->newPhotos);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:5000'],
            'capacity_adults' => ['required', 'integer', 'min:1', 'max:50'],
            'capacity_children' => ['required', 'integer', 'min:0', 'max:50'],
            'base_rate' => ['required', 'integer', 'min:1'],
            'breakfast_included_rate' => ['nullable', 'integer', 'min:1', 'gte:base_rate'],
            'monthly_rate' => ['nullable', 'integer', 'min:0'],
            'min_nights' => ['required', 'integer', 'min:1', 'max:90'],
            'weekly_discount_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'monthly_discount_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'deposit_type' => ['required', Rule::in(array_map(fn ($c) => $c->value, DepositType::cases()))],
            'deposit_value' => ['nullable', 'integer', 'min:0'],
            'booking_type' => ['required', Rule::in(array_map(fn ($c) => $c->value, RoomBookingType::cases()))],
            'status' => ['required', Rule::in(array_map(fn ($c) => $c->value, RoomStatus::cases()))],
            'rules' => ['nullable', 'string', 'max:5000'],
            'newPhotos' => ['array', 'max:20'],
            'newPhotos.*' => ['image', 'mimes:jpeg,png,webp', 'max:5120'],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        $tenant = Tenant::current();
        $propertyId = $tenant?->property?->id;
        if ($propertyId === null) {
            session()->flash('error', 'Set up your property before creating room categories.');

            return;
        }

        $category = $this->categoryId
            ? RoomCategory::query()->findOrFail($this->categoryId)
            : new RoomCategory;

        $category->fill([
            'property_id' => $propertyId,
            'name' => $data['name'],
            'type' => $data['type'],
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'capacity_adults' => (int) $data['capacity_adults'],
            'capacity_children' => (int) $data['capacity_children'],
            'base_rate' => (int) $data['base_rate'],
            'breakfast_included_rate' => $data['breakfast_included_rate'] !== null
                ? (int) $data['breakfast_included_rate']
                : null,
            'monthly_rate' => $data['monthly_rate'] !== null ? (int) $data['monthly_rate'] : null,
            'min_nights' => (int) $data['min_nights'],
            'weekly_discount_percent' => (int) $data['weekly_discount_percent'],
            'monthly_discount_percent' => (int) $data['monthly_discount_percent'],
            'deposit_type' => $data['deposit_type'],
            'deposit_value' => $data['deposit_value'] !== null ? (int) $data['deposit_value'] : 0,
            'booking_type' => $data['booking_type'],
            'status' => $data['status'],
            'rules' => $data['rules'] !== '' ? $data['rules'] : null,
        ]);
        $category->save();
        $this->categoryId = $category->id;

        // Append uploaded photos.
        if (! empty($this->newPhotos)) {
            foreach ($this->newPhotos as $photo) {
                $category->addMedia($photo->getRealPath())
                    ->usingName((string) $photo->getClientOriginalName())
                    ->toMediaCollection('gallery');
            }
            $this->newPhotos = [];
        }

        // Sync the attached checklist bundles. The pivot rows are
        // authoritative, we just resend the id set and let sync()
        // handle add / remove. Bundles themselves are authored on
        // the Housekeeping page; this form only picks which ones
        // apply to this category.
        $cleanIds = array_values(array_unique(array_map(
            'intval',
            $this->attachedChecklistIds,
        )));
        // Constrain to bundles that belong to this tenant, so a
        // hand-crafted payload can't attach a foreign bundle.
        $allowedIds = \App\Models\HousekeepingChecklist::query()
            ->where('tenant_id', $category->tenant_id)
            ->whereIn('id', $cleanIds)
            ->pluck('id')
            ->all();
        $category->checklists()->sync($allowedIds);

        session()->flash('status', $this->categoryId ? 'Category updated.' : 'Category created.');

        $this->redirectRoute('tenant.room-categories.index', navigate: true);
    }

    public function render(): View
    {
        $category = $this->categoryId ? RoomCategory::query()->find($this->categoryId) : null;

        return view('livewire.dashboard.room-categories.form', [
            'gallery' => $category ? $category->getMedia('gallery') : collect(),
            'depositOptions' => DepositType::cases(),
            'bookingTypeOptions' => RoomBookingType::cases(),
            'statusOptions' => RoomStatus::cases(),
            'availableChecklists' => \App\Models\HousekeepingChecklist::query()
                ->orderBy('name')
                ->withCount('items')
                ->get(),
        ]);
    }
}

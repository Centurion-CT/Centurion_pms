<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Discounts;

use App\Enums\BookingSource;
use App\Enums\DiscountType;
use App\Models\Discount;
use App\Models\Room;
use App\Models\RoomCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    public ?int $discountId = null;

    public string $name = '';
    public string $code = '';                      // empty = auto-apply
    public string $type = 'percent';
    public ?int $value = null;
    public ?int $min_nights = null;
    public ?int $max_nights = null;
    public string $starts_on = '';
    public string $ends_on = '';

    /** @var array<int, string> */
    public array $channels = [];                  // empty = no channel restriction

    /** @var array<int, int> */
    public array $rooms = [];                     // empty = no room-level targeting

    /** @var array<int, int> */
    public array $roomCategories = [];            // empty = no category-level targeting

    public ?int $max_uses = null;
    public bool $active = true;
    public string $description = '';

    public function mount(?Discount $discount = null): void
    {
        if ($discount && $discount->exists) {
            $this->discountId = $discount->id;
            $this->name = $discount->name;
            $this->code = (string) $discount->code;
            $this->type = $discount->type->value;
            $this->value = $discount->value;
            $this->min_nights = $discount->min_nights;
            $this->max_nights = $discount->max_nights;
            $this->starts_on = $discount->starts_on?->toDateString() ?? '';
            $this->ends_on = $discount->ends_on?->toDateString() ?? '';
            $this->channels = is_array($discount->channels) ? $discount->channels : [];
            $this->rooms = $discount->rooms()->pluck('rooms.id')->all();
            $this->roomCategories = $discount->roomCategories()->pluck('room_categories.id')->all();
            $this->max_uses = $discount->max_uses;
            $this->active = (bool) $discount->active;
            $this->description = (string) $discount->description;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $tenantId = (int) \App\Models\Tenant::current()?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('discounts', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at'))
                    ->ignore($this->discountId),
            ],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'integer', 'min:1', $this->type === 'percent' ? 'max:100' : 'max:10000000'],
            'min_nights' => ['nullable', 'integer', 'min:1', 'max:365'],
            'max_nights' => ['nullable', 'integer', 'min:1', 'max:365', 'gte:min_nights'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'channels' => ['array'],
            // Every value the picker offers (Online + operator-facing
            // channels + Manual) must be accepted here, otherwise
            // ticking anything beyond Online/Manual fails validation.
            'channels.*' => [Rule::in(array_map(
                fn (BookingSource $c) => $c->value,
                [BookingSource::Online, ...BookingSource::pickerCases(), BookingSource::Manual],
            ))],
            'rooms' => ['array'],
            'rooms.*' => ['integer', 'exists:rooms,id'],
            'roomCategories' => ['array'],
            'roomCategories.*' => ['integer', 'exists:room_categories,id'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        $discount = $this->discountId
            ? Discount::query()->forCurrentProperty()->findOrFail($this->discountId)
            : new Discount;

        $discount->fill([
            'name' => $data['name'],
            'code' => $data['code'] !== '' ? mb_strtoupper(trim($data['code'])) : null,
            'type' => $data['type'],
            'value' => (int) $data['value'],
            'min_nights' => $data['min_nights'] !== null ? (int) $data['min_nights'] : null,
            'max_nights' => $data['max_nights'] !== null ? (int) $data['max_nights'] : null,
            'starts_on' => $data['starts_on'] !== '' ? $data['starts_on'] : null,
            'ends_on' => $data['ends_on'] !== '' ? $data['ends_on'] : null,
            'channels' => $data['channels'] !== [] ? array_values($data['channels']) : null,
            'max_uses' => $data['max_uses'] !== null ? (int) $data['max_uses'] : null,
            'active' => (bool) $data['active'],
            'description' => $data['description'] !== '' ? $data['description'] : null,
        ]);

        if (! $discount->exists) {
            $discount->created_by_operator_id = auth('web')->id();
        }
        $discount->save();

        // Sync targeting. Empty list = no restriction at that layer;
        // both pivots empty = "applies to every room on the property".
        $discount->rooms()->sync($data['rooms'] ?? []);
        $discount->roomCategories()->sync($data['roomCategories'] ?? []);

        session()->flash('status', $this->discountId ? 'Discount updated.' : 'Discount created.');

        $this->redirectRoute('tenant.discounts.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.dashboard.discounts.form', [
            'allRooms' => Room::query()->active()->with('category')->orderBy('room_number')->get(),
            'allCategories' => RoomCategory::query()->orderBy('name')->get(),
            // Includes Online (guest booking page) + every operator-facing
            // channel so a discount can be scoped e.g. "Airbnb only" or
            // "walk-ins only". Legacy Manual stays selectable so old rules
            // keep working.
            'channelOptions' => array_map(
                fn (BookingSource $c) => ['value' => $c->value, 'label' => $c->label()],
                [BookingSource::Online, ...BookingSource::pickerCases(), BookingSource::Manual],
            ),
            'typeOptions' => DiscountType::cases(),
        ]);
    }
}

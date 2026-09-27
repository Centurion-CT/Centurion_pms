<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Rooms;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Single Livewire component handling both /rooms/create and
 * /rooms/{room}/edit (PRD §6.3.1).
 *
 * Post-categories refactor: shared fields (pricing, capacity,
 * description, booking type, deposits, photos) live on
 * {@see RoomCategory}. This form asks the operator to:
 *
 *   1. Pick an existing category, links the room to a class/type
 *   2. Set the unit's room_number (e.g. "101", "Garden Cottage")
 *   3. Choose status (visibility)
 *   4. Capture internal staff notes (optional)
 *
 * Operators wanting to create a brand-new category before this room
 * exists can jump to /dashboard/room-categories/new from the empty
 * state in the category dropdown.
 */
#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    #[Locked]
    public ?int $roomId = null;

    public ?int $room_category_id = null;

    public string $room_number = '';

    /**
     * Create-mode bulk buffer. Each committed badge lives here; the
     * `room_number` field above is the typing buffer that becomes a
     * badge on comma / Enter (or is flushed here on submit if the
     * operator forgot to commit it).
     *
     * @var list<string>
     */
    public array $room_numbers = [];

    public string $status = 'active';

    public string $internal_notes = '';

    public function mount(?Room $room = null): void
    {
        // Edit mode: the route resolves Room via tenant-scoped query,
        // which returns null for foreign-tenant ids -> 404.
        if ($room !== null && $room->exists) {
            $this->roomId = $room->id;
            $this->room_category_id = $room->room_category_id;
            $this->room_number = (string) ($room->getAttributes()['room_number'] ?? '');
            $this->status = $room->status->value;
            $this->internal_notes = (string) ($room->getAttributes()['internal_notes'] ?? '');
        }
    }

    /**
     * Push a token onto the bulk buffer. Idempotent, duplicates and
     * blanks are silently dropped so the operator can paste a messy
     * list ("101, 102, ,101") without extra cleanup.
     */
    public function addRoomNumber(string $value): void
    {
        if ($this->roomId !== null) {
            return; // edit mode, single unit only
        }

        // Split on comma or newline so paste "101,102" or multi-line
        // paste both work in one call.
        $parts = preg_split('/[,\n\r]+/', $value) ?: [];
        foreach ($parts as $part) {
            $clean = trim((string) $part);
            if ($clean === '' || strlen($clean) > 40) {
                continue;
            }
            if (in_array($clean, $this->room_numbers, true)) {
                continue;
            }
            $this->room_numbers[] = $clean;
        }
        $this->room_number = '';
    }

    public function removeRoomNumber(int $index): void
    {
        if (! array_key_exists($index, $this->room_numbers)) {
            return;
        }
        unset($this->room_numbers[$index]);
        $this->room_numbers = array_values($this->room_numbers);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $tenantId = (int) Tenant::current()?->id;
        $propertyId = Tenant::current()?->property?->id;

        // Unique per property, front desk relies on the number to
        // look up a guest by their key fob etc.
        $uniquePerProperty = Rule::unique('rooms', 'room_number')
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                ->where('property_id', $propertyId)
                ->whereNull('deleted_at'));

        if ($this->roomId !== null) {
            // Edit mode, single-unit, keeps the existing shape so the
            // form behaves identically to how it always has.
            return [
                'room_category_id' => ['required', 'integer', 'exists:room_categories,id'],
                'room_number' => [
                    'required', 'string', 'max:40',
                    (clone $uniquePerProperty)->ignore($this->roomId),
                ],
                'status' => ['required', Rule::in(array_map(fn ($c) => $c->value, RoomStatus::cases()))],
                'internal_notes' => ['nullable', 'string', 'max:5000'],
            ];
        }

        return [
            'room_category_id' => ['required', 'integer', 'exists:room_categories,id'],
            'room_numbers' => ['required', 'array', 'min:1'],
            'room_numbers.*' => [
                'string', 'max:40', 'distinct:strict',
                $uniquePerProperty,
            ],
            'status' => ['required', Rule::in(array_map(fn ($c) => $c->value, RoomStatus::cases()))],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'room_numbers' => 'room numbers',
            'room_numbers.*' => 'room number',
        ];
    }

    public function save(): void
    {
        // In create mode, flush any residual buffer text into the
        // badge list first, operators shouldn't be forced to hit
        // Enter for the last token before submitting.
        if ($this->roomId === null && trim($this->room_number) !== '') {
            $this->addRoomNumber($this->room_number);
        }

        $data = $this->validate();

        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $property = $tenant?->property;
        if ($property === null) {
            throw new NotFoundHttpException('No property, finish onboarding first.');
        }

        // Defensive: confirm the picked category lives under the same
        // property (route model binding isn't in play here).
        $category = RoomCategory::query()
            ->where('property_id', $property->id)
            ->findOrFail($data['room_category_id']);

        // Post-Phase 2 Room schema is lean, only per-unit fields are
        // stored here. Pricing / capacity / type / description / etc.
        // live on the linked RoomCategory and are surfaced via
        // accessors at read time.
        if ($this->roomId === null) {
            $notes = $data['internal_notes'] !== '' ? $data['internal_notes'] : null;
            $created = 0;
            foreach ($data['room_numbers'] as $number) {
                $room = new Room;
                $room->property_id = $property->id;
                $room->forceFill([
                    'room_category_id' => $category->id,
                    'room_number' => $number,
                    'internal_notes' => $notes,
                    'status' => RoomStatus::from($data['status']),
                ]);
                $room->save();
                $created++;
            }

            session()->flash('status', __(
                $created === 1 ? 'Room added.' : ':n rooms added.',
                ['n' => $created],
            ));
        } else {
            $room = Room::query()->findOrFail($this->roomId);
            $room->forceFill([
                'room_category_id' => $category->id,
                'room_number' => $data['room_number'],
                'internal_notes' => $data['internal_notes'] !== '' ? $data['internal_notes'] : null,
                'status' => RoomStatus::from($data['status']),
            ]);
            $room->save();

            session()->flash('status', __('Room updated.'));
        }

        $this->redirectRoute('tenant.rooms.index');
    }

    public function render(): View
    {
        $property = Tenant::current()?->property;
        $categories = $property
            ? RoomCategory::query()->where('property_id', $property->id)
                ->orderBy('name')->get()
            : collect();

        return view('livewire.dashboard.rooms.form', [
            'categories' => $categories,
            'statuses' => RoomStatus::cases(),
        ]);
    }
}

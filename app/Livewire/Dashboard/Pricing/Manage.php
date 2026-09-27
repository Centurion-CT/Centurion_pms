<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Pricing;

use App\Enums\DepositType;
use App\Models\Room;
use App\Models\RoomPrice;
use App\Services\Pricing\PricingBreakdown;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

/**
 * Per-room pricing UI (PRD §6.3.2). One component handles:
 *  - Editing discount % + deposit knobs
 *  - Adding/removing seasonal RoomPrice overrides (with overlap rejection)
 *  - Live price preview for any [checkin, checkout) range via PricingCalculator
 *
 * Route-model-binds {room} so foreign-tenant ids 404 via BelongsToTenant.
 */
#[Layout('layouts.tenant-auth')]
class Manage extends Component
{
    #[Locked]
    public int $roomId;

    // Discount + deposit form bindings (mirror Room columns)
    #[Validate('required|integer|min:0|max:100')]
    public int $weekly_discount_percent = 0;

    #[Validate('required|integer|min:0|max:100')]
    public int $monthly_discount_percent = 0;

    #[Validate('required|in:none,fixed,percent')]
    public string $deposit_type = 'percent';

    #[Validate('required|integer|min:0|max:50000000')]
    public int $deposit_value = 30;

    // Override form bindings
    #[Validate('required|date')]
    public string $override_start = '';

    #[Validate('required|date|after_or_equal:override_start')]
    public string $override_end = '';

    #[Validate('required|integer|min:1|max:50000000')]
    public int $override_rate = 0;

    #[Validate('nullable|string|max:80')]
    public string $override_label = '';

    // Preview bindings
    public string $preview_checkin = '';

    public string $preview_checkout = '';

    public function mount(Room $room): void
    {
        $this->roomId = $room->id;
        $this->weekly_discount_percent = $room->weekly_discount_percent;
        $this->monthly_discount_percent = $room->monthly_discount_percent;
        $this->deposit_type = $room->deposit_type->value;
        $this->deposit_value = $room->deposit_value;

        $this->preview_checkin = Carbon::tomorrow()->toDateString();
        $this->preview_checkout = Carbon::tomorrow()->addDays(3)->toDateString();
    }

    public function saveSettings(): void
    {
        $this->validateOnly('weekly_discount_percent');
        $this->validateOnly('monthly_discount_percent');
        $this->validateOnly('deposit_type');
        $this->validateOnly('deposit_value');

        $room = $this->room();
        $room->forceFill([
            'weekly_discount_percent' => $this->weekly_discount_percent,
            'monthly_discount_percent' => $this->monthly_discount_percent,
            'deposit_type' => DepositType::from($this->deposit_type),
            'deposit_value' => $this->deposit_value,
        ])->save();

        session()->flash('status', __('Pricing settings saved.'));
    }

    public function addOverride(): void
    {
        $this->validateOnly('override_start');
        $this->validateOnly('override_end');
        $this->validateOnly('override_rate');
        $this->validateOnly('override_label');

        $start = Carbon::parse($this->override_start);
        $end = Carbon::parse($this->override_end);

        $room = $this->room();
        $clash = $room->prices()->overlapping($start, $end)->exists();
        if ($clash) {
            throw ValidationException::withMessages([
                'override_start' => __('That range overlaps an existing seasonal price. Edit or delete it first.'),
            ]);
        }

        RoomPrice::query()->create([
            'tenant_id' => $room->tenant_id,
            'room_id' => $room->id,
            'start_date' => $start,
            'end_date' => $end,
            'rate' => $this->override_rate,
            'label' => $this->override_label !== '' ? $this->override_label : null,
        ]);

        $this->override_start = '';
        $this->override_end = '';
        $this->override_rate = 0;
        $this->override_label = '';

        session()->flash('status', __('Seasonal price added.'));
    }

    public function deleteOverride(int $id): void
    {
        $price = RoomPrice::query()->where('room_id', $this->roomId)->find($id);
        if ($price !== null) {
            $price->delete();
            session()->flash('status', __('Seasonal price removed.'));
        }
    }

    public function render(): View
    {
        $room = $this->room();
        $overrides = $room->prices()->orderBy('start_date')->get();

        $preview = $this->buildPreview($room);

        return view('livewire.dashboard.pricing.manage', [
            'room' => $room,
            'overrides' => $overrides,
            'preview' => $preview,
        ]);
    }

    private function buildPreview(Room $room): ?PricingBreakdown
    {
        if ($this->preview_checkin === '' || $this->preview_checkout === '') {
            return null;
        }

        try {
            $checkin = Carbon::parse($this->preview_checkin);
            $checkout = Carbon::parse($this->preview_checkout);
        } catch (Throwable) {
            return null;
        }

        if ($checkout->lessThanOrEqualTo($checkin)) {
            return null;
        }

        return app(PricingCalculator::class)->calculate($room, $checkin, $checkout);
    }

    private function room(): Room
    {
        return Room::query()->findOrFail($this->roomId);
    }
}

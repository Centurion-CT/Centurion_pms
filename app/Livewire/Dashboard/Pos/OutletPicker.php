<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Pos;

use App\Enums\OperatorRole;
use App\Livewire\Concerns\UsesPosKioskLayout;
use App\Models\Outlet;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Landing page for the POS module. Managers see every active outlet;
 * PosStaff see only outlets they've been explicitly assigned to via
 * the outlet_operators pivot.
 *
 * If the operator has exactly one outlet, we auto-redirect straight
 * to the terminal so they skip the pick step every shift.
 */
// Layout chosen dynamically via UsesPosKioskLayout, see Terminal.php.
class OutletPicker extends Component
{
    use UsesPosKioskLayout;

    public function render(): View
    {
        $operator = auth('web')->user();
        $isManager = $operator?->role === OperatorRole::Manager;

        // Housekeeping outlets are supply rooms, not points of sale —
        // they must never appear in the terminal picker. Their type's
        // usesPos() returns false and we filter on it here (plus in
        // OutletShow's request pipeline check further down).
        $nonPosTypes = collect(\App\Enums\OutletType::cases())
            ->reject(fn (\App\Enums\OutletType $t) => $t->usesPos())
            ->map(fn (\App\Enums\OutletType $t) => $t->value)
            ->all();

        $outlets = $isManager
            ? Outlet::query()->active()->whereNotIn('type', $nonPosTypes)->orderBy('name')->get()
            : $operator->outlets()->where('active', true)->whereNotIn('type', $nonPosTypes)->orderBy('name')->get();

        // Single-outlet shortcut, bounce straight into the terminal.
        if ($outlets->count() === 1) {
            $this->redirectRoute('tenant.pos.terminal', ['outlet' => $outlets->first()->id], navigate: false);
        }

        return $this->applyKioskLayout(view('livewire.dashboard.pos.outlet-picker', [
            'outlets'    => $outlets,
            'isManager'  => $isManager,
        ]));
    }
}

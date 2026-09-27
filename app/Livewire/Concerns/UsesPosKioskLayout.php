<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\OperatorRole;
use Illuminate\Contracts\View\View;

/**
 * Applies the full-screen POS layout for PosStaff operators.
 *
 * The pattern: individual Livewire POS components declare
 * `#[Layout('layouts.tenant-auth')]` for managers (who need the full
 * dashboard shell to move between modules) and then call
 * `$view = $this->applyKioskLayout($view)` at the end of render() to
 * swap to the kiosk layout when the signed-in operator is PosStaff.
 *
 * Keeping this as a small trait rather than a base class lets each POS
 * component stay a plain Livewire\Component with its own inheritance.
 */
trait UsesPosKioskLayout
{
    protected function applyKioskLayout(View $view): View
    {
        $layout = auth('web')->user()?->role === OperatorRole::PosStaff
            ? 'layouts.pos-kiosk'
            : 'layouts.tenant-auth';

        return $view->layout($layout);
    }
}

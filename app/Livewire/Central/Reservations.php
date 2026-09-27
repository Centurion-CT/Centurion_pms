<?php

declare(strict_types=1);

namespace App\Livewire\Central;

use App\Models\IdentityAccount;
use App\Services\Identity\GuestReservationLookup;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Central guest dashboard (docs/unified-login.md §3 "Guest dashboard
 * scope v1", Phase 2c). Aggregates reservations across every tenant
 * the identity has a guest link on.
 *
 * The lookup service does the cross-tenant read; this component only
 * knows about presentation. Explicitly does NOT bind a tenant, every
 * booking row carries its tenant on the model, and the view renders
 * per-row property names.
 */
#[Layout('layouts.auth')]
class Reservations extends Component
{
    public function render(GuestReservationLookup $lookup): View
    {
        /** @var IdentityAccount $identity */
        $identity = auth('identity')->user();

        ['upcoming' => $upcoming, 'past' => $past] = $lookup->forIdentity($identity);

        return view('livewire.central.reservations', [
            'identity' => $identity,
            'upcoming' => $upcoming,
            'past' => $past,
        ]);
    }
}

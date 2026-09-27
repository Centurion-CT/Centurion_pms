<?php

declare(strict_types=1);

namespace App\Livewire\Central;

use App\Models\IdentityAccount;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Account chooser. Shows one card per operator link plus a "My
 * reservations" card if the identity has guest links. Phase 1b renders
 * only operator cards (guest links come in Phase 2).
 *
 * Cards are ordered by last_login_at desc so the tenant the user opened
 * most recently is first, see docs/unified-login.md §3 chooser section.
 */
#[Layout('layouts.auth')]
class Chooser extends Component
{
    public function render(): View
    {
        /** @var IdentityAccount $identity */
        $identity = auth('identity')->user();

        $links = $identity->operatorLinks()
            ->with(['tenant', 'operator'])
            ->orderByRaw('last_login_at IS NULL')
            ->orderByDesc('last_login_at')
            ->get();

        return view('livewire.central.chooser', [
            'identity' => $identity,
            'links' => $links,
            'hasGuestLinks' => $identity->guestLinks()->exists(),
        ]);
    }
}

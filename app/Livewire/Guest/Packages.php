<?php

declare(strict_types=1);

namespace App\Livewire\Guest;

use App\Actions\Bookings\BookingValidationException;
use App\Actions\Packages\AddPackageToBooking;
use App\Livewire\Guest\Concerns\ResolvesPortalBooking;
use App\Models\Package;
use App\Services\Bookings\GuestPortalTokenService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Guest-portal page for browsing + adding add-on packages after a
 * booking has been created. Complimentary additions are free; paid
 * additions create a BookingCharge so the existing balance flow
 * handles settlement.
 */
class Packages extends Component
{
    use ResolvesPortalBooking;

    public function mount(string $bookingRef, GuestPortalTokenService $tokens): void
    {
        $this->mountPortalBooking($bookingRef, (string) request()->query('token', ''), $tokens);
    }

    public function add(int $packageId, AddPackageToBooking $action, GuestPortalTokenService $tokens): void
    {
        $booking = $this->loadBooking($tokens);

        $package = Package::query()
            ->where('tenant_id', $booking->tenant_id)
            ->where('id', $packageId)
            ->first();
        if ($package === null) {
            throw ValidationException::withMessages(['package' => 'That package is no longer offered.']);
        }

        try {
            $action->handle($booking, $package, 1);
        } catch (BookingValidationException $e) {
            throw ValidationException::withMessages(['package' => $e->getMessage()]);
        }

        session()->flash('status', sprintf('"%s" added to your booking.', $package->name));
    }

    public function render(GuestPortalTokenService $tokens): View
    {
        $booking = $this->loadBooking($tokens);

        $selected = $booking->selectedPackages()->get();

        $catalog = Package::query()
            ->where('tenant_id', $booking->tenant_id)
            ->active()
            ->forPostBookingFlow()
            ->orderBy('id')
            ->get();

        // Filter out catalog items the guest can't add more of (already
        // at max_per_booking based on existing selections).
        $alreadyQty = $selected->groupBy('package_id')->map(fn ($lines) => (int) $lines->sum('quantity'));
        $available = $catalog->filter(
            fn (Package $p) => (int) $alreadyQty->get($p->id, 0) < (int) $p->max_per_booking,
        )->values();

        return view('livewire.guest.packages', [
            'booking'  => $booking,
            'selected' => $selected,
            'available' => $available,
            'outstandingTotal' => $booking->outstandingTotal(),
        ])->layout('layouts.tenant-public');
    }
}

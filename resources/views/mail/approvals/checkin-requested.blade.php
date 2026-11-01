<x-mail::message>
# Approval needed: check-in without payment

**{{ $requesterName }}** at the front desk wants to check **{{ $guestName }}** in without collecting payment first. The outstanding will sit on the folio for later settlement.

- **Reservation:** {{ $bookingRef }}
- **Room:** {{ $roomLabel }}
- **Stay:** {{ $checkin }} → {{ $checkout }} ({{ $nights }} night{{ $nights === 1 ? '' : 's' }})
- **Amount owed:** ₦{{ number_format($totalNaira) }}

Approving triggers the check-in automatically. Rejecting leaves the reservation Pending. The desk will need to collect payment or drop the reservation.

<x-mail::button :url="$approvalUrl">
Review in Approvals
</x-mail::button>

Thanks,<br>{{ config('brand.name') }}
</x-mail::message>

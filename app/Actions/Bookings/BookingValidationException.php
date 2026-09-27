<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use RuntimeException;

/**
 * Surfaces a guest-facing validation failure from CreateBooking — capacity
 * over the room limit, past dates, under min_nights, etc. Distinct from
 * RangeNotAvailableException (which means the inventory raced away) so
 * the UI can route them to different messages.
 */
class BookingValidationException extends RuntimeException {}

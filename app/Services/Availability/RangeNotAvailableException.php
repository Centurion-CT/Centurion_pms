<?php

declare(strict_types=1);

namespace App\Services\Availability;

use RuntimeException;

/**
 * Raised when blockRange / reserveRangeForBooking hits the unique constraint
 * on availability_blocks (PRD §11). Callers should catch this and surface a
 * "those dates are no longer available" message to the operator/guest.
 */
class RangeNotAvailableException extends RuntimeException {}

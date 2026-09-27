<?php

declare(strict_types=1);

namespace App\Actions\CreditLines;

use App\Enums\BookingChargeType;
use App\Models\CreditLine;
use App\Models\CreditLineCharge;
use App\Models\Operator;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Add a line item to a credit line. Type is reused from BookingChargeType
 * so the vocabulary (food_beverage, room_service, damage, etc) is the
 * same as the guest folio.
 */
class AddCreditLineCharge
{
    public function handle(
        CreditLine $line,
        BookingChargeType $type,
        string $description,
        int $amount,
        ?Operator $operator = null,
        ?Room $room = null,
    ): CreditLineCharge {
        $description = trim($description);
        if ($description === '') {
            throw new InvalidArgumentException('Charge description cannot be empty.');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Charge amount must be positive.');
        }
        if (! $line->status->acceptsCharges()) {
            throw new RuntimeException(sprintf(
                'Credit line "%s" is %s — new charges are not accepted.',
                $line->name,
                $line->status->label(),
            ));
        }
        // System-only charge types can't be added manually here; they're
        // produced by ExtendBooking / MarkNoShow / etc.
        if (in_array($type, [BookingChargeType::Extension, BookingChargeType::DepositForfeit], true)) {
            throw new InvalidArgumentException(
                'System-only charge type — use ExtendBooking / MarkNoShow instead.'
            );
        }
        // If a room was passed, insist it's attached to the line so a
        // stray front-desk charge to an unattached room can't slip in.
        if ($room !== null) {
            $attached = $line->rooms()->where('rooms.id', $room->id)->exists();
            if (! $attached) {
                throw new RuntimeException(sprintf(
                    'Room %s isn\'t attached to credit line "%s". Attach it first.',
                    $room->room_number ?? (string) $room->id,
                    $line->name,
                ));
            }
        }

        return DB::transaction(function () use ($line, $type, $description, $amount, $operator, $room): CreditLineCharge {
            $charge = CreditLineCharge::query()->create([
                'tenant_id' => $line->tenant_id,
                'credit_line_id' => $line->id,
                'room_id' => $room?->id,
                'type' => $type->value,
                'description' => $description,
                'amount' => $amount,
                'added_by_operator_id' => $operator?->id,
            ]);

            activity('credit_line')
                ->causedBy($operator)
                ->performedOn($line)
                ->event('charge_added')
                ->withProperties([
                    'charge_id' => $charge->id,
                    'amount' => $amount,
                    'type' => $type->value,
                ])
                ->log(sprintf(
                    '%s added a %s charge of ₦%s to "%s": %s',
                    $operator?->name ?? 'System',
                    $type->label(),
                    number_format($amount),
                    $line->name,
                    $description,
                ));

            return $charge;
        });
    }
}

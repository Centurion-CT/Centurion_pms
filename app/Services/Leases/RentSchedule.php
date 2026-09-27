<?php

declare(strict_types=1);

namespace App\Services\Leases;

use App\Enums\LeasePaymentStatus;
use App\Models\Lease;
use App\Models\LeasePayment;
use Illuminate\Support\Carbon;

/**
 * Builds the rent-collection schedule for a Lease.
 *
 * Nigerian residential leases are typically billed in *annual blocks*
 * — the tenant pays one year up front, with the next year due 12
 * months later. We model that by writing one LeasePayment row per
 * year of the lease term, plus an optional deposit + service charge
 * row dated to the lease start.
 *
 * Idempotent — regenerating won't duplicate existing rows; it only
 * adds payments that don't yet exist.
 */
class RentSchedule
{
    public function regenerate(Lease $lease): void
    {
        $existingDueDates = $lease->payments()
            ->pluck('due_at')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $rows = [];

        // Deposit + service charge are due on day 1.
        if ($lease->deposit_naira > 0 && ! $this->hasLabel($lease, 'Deposit')) {
            $rows[] = [
                'due_at'       => $lease->started_at->toDateString(),
                'label'        => 'Deposit',
                'amount_naira' => $lease->deposit_naira,
                'status'       => LeasePaymentStatus::Due->value,
            ];
        }
        if ($lease->service_charge_naira > 0 && ! $this->hasLabel($lease, 'Service charge')) {
            $rows[] = [
                'due_at'       => $lease->started_at->toDateString(),
                'label'        => 'Service charge',
                'amount_naira' => $lease->service_charge_naira,
                'status'       => LeasePaymentStatus::Due->value,
            ];
        }

        // Annual rent rows — one per year. If the term is 12 months,
        // that's one row. 24 months → two rows. Operators with
        // unusual term lengths (15 months, 30) can edit individual
        // rows after generation.
        $years = max(1, (int) ceil($lease->term_months / 12));
        $rent = $lease->annual_rent_naira;

        for ($i = 0; $i < $years; $i++) {
            $due = $lease->started_at->copy()->addYears($i)->toDateString();
            if (in_array($due, $existingDueDates, true)) {
                continue;
            }
            $rows[] = [
                'due_at'       => $due,
                'label'        => 'Year '.($i + 1).' rent',
                'amount_naira' => $rent,
                'status'       => $i === 0
                    ? LeasePaymentStatus::Due->value
                    : LeasePaymentStatus::Upcoming->value,
            ];
        }

        foreach ($rows as $row) {
            $lease->payments()->create($row);
        }
    }

    private function hasLabel(Lease $lease, string $label): bool
    {
        return $lease->payments()->where('label', $label)->exists();
    }
}

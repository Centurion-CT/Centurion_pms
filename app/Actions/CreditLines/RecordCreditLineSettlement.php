<?php

declare(strict_types=1);

namespace App\Actions\CreditLines;

use App\Models\CreditLine;
use App\Models\CreditLineSettlement;
use App\Models\Operator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Record an offline payment against the credit line. Amount is capped
 * at the current outstanding so an operator can't accidentally over-
 * settle (and produce a negative balance). If the guest overpays,
 * that's a separate refund / adjustment concern.
 */
class RecordCreditLineSettlement
{
    /** @var list<string> */
    public const METHODS = ['cash', 'transfer', 'card', 'paystack', 'other'];

    public function handle(
        CreditLine $line,
        int $amount,
        string $method,
        ?string $referenceNote = null,
        ?Operator $operator = null,
    ): CreditLineSettlement {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Settlement amount must be positive.');
        }
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("Unsupported settlement method: {$method}.");
        }
        if (! $line->status->acceptsSettlements()) {
            throw new RuntimeException(sprintf(
                'Credit line "%s" is %s — settlements are frozen.',
                $line->name,
                $line->status->label(),
            ));
        }

        $outstanding = $line->outstandingTotal();
        if ($outstanding <= 0) {
            throw new RuntimeException('Nothing outstanding to settle.');
        }
        if ($amount > $outstanding) {
            throw new RuntimeException(sprintf(
                'Settlement amount ₦%s exceeds outstanding ₦%s.',
                number_format($amount),
                number_format($outstanding),
            ));
        }

        return DB::transaction(function () use ($line, $amount, $method, $referenceNote, $operator): CreditLineSettlement {
            $settlement = CreditLineSettlement::query()->create([
                'tenant_id' => $line->tenant_id,
                'credit_line_id' => $line->id,
                'amount' => $amount,
                'method' => $method,
                'reference_note' => $referenceNote !== null && trim($referenceNote) !== ''
                    ? trim($referenceNote) : null,
                'recorded_by_operator_id' => $operator?->id,
                'settled_at' => Carbon::now(),
            ]);

            activity('credit_line')
                ->causedBy($operator)
                ->performedOn($line)
                ->event('settlement_recorded')
                ->withProperties([
                    'settlement_id' => $settlement->id,
                    'amount' => $amount,
                    'method' => $method,
                ])
                ->log(sprintf(
                    '%s recorded a ₦%s settlement on "%s" via %s.',
                    $operator?->name ?? 'System',
                    number_format($amount),
                    $line->name,
                    $method,
                ));

            return $settlement;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Multitenancy\BelongsToTenant;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property SubscriptionPlan $plan
 * @property int $amount             legacy billing figure (mirror of amount_received)
 * @property int $contract_amount    total agreed for the period (NGN)
 * @property int $amount_received    what tenant has paid so far (NGN)
 * @property int $duration_months
 * @property string|null $agreement_notes
 * @property SubscriptionStatus $status
 * @property string|null $paystack_reference
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property Carbon|null $paid_at
 * @property array<int,int>|null $reminders_sent
 * @property-read Tenant $tenant
 */
class Subscription extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => SubscriptionPlan::class,
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'reminders_sent' => 'array',
        ];
    }

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }

    public function isLive(): bool
    {
        return $this->status->isLive() && $this->expires_at->isFuture();
    }

    /**
     * Whole days between now and expires_at, rounded up so an
     * expiry ~6 hours from now still reads as "1 day left" rather
     * than "0 days". Returns 0 for already-expired subs.
     */
    public function daysUntilExpiry(): int
    {
        if ($this->expires_at->isPast()) {
            return 0;
        }
        return (int) ceil(\Illuminate\Support\Carbon::now()
            ->diffInDays($this->expires_at, absolute: false));
    }

    /**
     * Whether the sub is close enough to expiry that we want a
     * top-bar banner to prompt renewal. Two thresholds — trials are
     * short so a 5-day heads-up matches perceived urgency; annual
     * plans get 14 days so ops has time to route the payment.
     */
    public function isEndingSoon(): bool
    {
        if (! $this->isLive()) {
            return false;
        }
        $threshold = $this->plan === \App\Enums\SubscriptionPlan::Trial ? 5 : 14;

        return $this->daysUntilExpiry() <= $threshold;
    }

    /**
     * Outstanding on the agreed contract. Zero when the tenant has
     * paid the full agreement (or overpaid — surplus doesn't roll
     * negative; the admin can adjust manually if needed).
     */
    public function outstanding(): int
    {
        $agreed   = (int) ($this->contract_amount ?: $this->amount);
        $received = (int) ($this->amount_received ?: $this->amount);
        return max(0, $agreed - $received);
    }

    public function isFullyPaid(): bool
    {
        return $this->outstanding() === 0;
    }
}

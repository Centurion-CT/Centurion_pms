<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Membership;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\MembershipSubscription;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Subscription detail. One page for the four operational actions a
 * front-desk hits during a member's lifecycle:
 *   - renew: shift the period forward by plan.validity_days and log
 *     a KIND_RENEWAL payment.
 *   - extend: tack days onto the current expires_at without a fresh
 *     period. Common when the guest missed some days and the
 *     manager grants a make-good.
 *   - cancel: flip status to Cancelled, no money moves.
 *   - take payment: record a receipt without changing dates (for a
 *     late top-up or a partial that doesn't unlock a period).
 */
#[Layout('layouts.tenant-auth')]
class Show extends Component
{
    #[Locked]
    public int $subscriptionId;

    // Renew form
    public bool $renewOpen = false;

    public ?int $renewPlanId = null;

    public ?int $renewAmount = null;

    public string $renewMethod = 'cash';

    // Extend form
    public bool $extendOpen = false;

    public ?int $extendDays = null;

    public ?int $extendAmount = null;

    public string $extendMethod = 'cash';

    // Take payment form
    public bool $paymentOpen = false;

    public ?int $paymentAmount = null;

    public string $paymentMethod = 'cash';

    public string $paymentNote = '';

    // Cancel form
    public bool $cancelOpen = false;

    public string $cancelReason = '';

    public function mount(string $memberRef): void
    {
        $sub = MembershipSubscription::query()
            ->where('member_ref', $memberRef)
            ->firstOrFail();
        $this->subscriptionId = $sub->id;
    }

    // ── Renew ─────────────────────────────────────────────────────

    public function toggleRenew(): void
    {
        $this->renewOpen = ! $this->renewOpen;
        if ($this->renewOpen) {
            $sub = $this->subscription();
            $this->renewPlanId = $sub->plan_id;
            $this->renewAmount = $sub->plan?->price;
            $this->renewMethod = 'cash';
        }
    }

    public function updatedRenewPlanId(): void
    {
        if ($this->renewPlanId !== null) {
            $plan = MembershipPlan::query()->find($this->renewPlanId);
            if ($plan !== null) {
                $this->renewAmount = $plan->price;
            }
        }
    }

    public function renew(): void
    {
        try {
            $sub = $this->subscription();
            if ($sub->status === MembershipSubscription::STATUS_CANCELLED) {
                throw new RuntimeException('Cancelled subscriptions can\'t be renewed. Sign the customer up again.');
            }
            if ($this->renewPlanId === null) {
                throw new RuntimeException('Pick a plan.');
            }
            $plan = MembershipPlan::query()->find($this->renewPlanId);
            if ($plan === null || ! $plan->active) {
                throw new RuntimeException('Plan not found or inactive.');
            }
            if ($this->renewAmount === null || $this->renewAmount < 0) {
                throw new RuntimeException('Enter a payment amount.');
            }
            if (! in_array($this->renewMethod, ['cash', 'transfer', 'card', 'pos', 'other'], true)) {
                throw new RuntimeException('Pick a payment method.');
            }

            $operator = auth('web')->user();
            $now = Carbon::now();

            DB::transaction(function () use ($sub, $plan, $operator, $now): void {
                // Roll forward from the later of "now" and current
                // expiry — a still-active subscription stacks on top
                // of the remaining time, an expired one starts fresh.
                $rollFrom = $sub->expires_at->greaterThan($now) ? $sub->expires_at : $now;
                $newExpires = $rollFrom->copy()->addDays($plan->validity_days);
                $periodStart = $sub->expires_at->greaterThan($now)
                    ? $sub->expires_at->copy()
                    : $now->copy();

                $sub->forceFill([
                    'plan_id'    => $plan->id,
                    'expires_at' => $newExpires,
                    'status'     => MembershipSubscription::STATUS_ACTIVE,
                ])->save();

                if ((int) $this->renewAmount > 0) {
                    Payment::query()->create([
                        'membership_subscription_id' => $sub->id,
                        'paystack_reference'         => Members::freshPaymentRef(),
                        'amount'                     => (int) $this->renewAmount,
                        'type'                       => PaymentType::Extra,
                        'method'                     => $this->renewMethod,
                        'status'                     => PaymentStatus::Succeeded,
                        'membership_kind'            => MembershipPayment::KIND_RENEWAL,
                        'membership_period_start'    => $periodStart,
                        'membership_period_end'      => $newExpires,
                        'reference_note'             => 'Membership renewal: '.$plan->name,
                        'collected_by_operator_id'   => $operator?->getKey(),
                        'paid_at'                    => $now,
                    ]);
                }
            });

            $this->renewOpen = false;
            $this->dispatch('notify',
                title: 'Renewed',
                message: 'Membership renewed to '.$this->subscription()->refresh()->expires_at->toFormattedDateString().'.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t renew', message: $e->getMessage(), kind: 'err');
        }
    }

    // ── Extend ────────────────────────────────────────────────────

    public function toggleExtend(): void
    {
        $this->extendOpen = ! $this->extendOpen;
        if ($this->extendOpen) {
            $this->extendDays = null;
            $this->extendAmount = null;
            $this->extendMethod = 'cash';
        }
    }

    public function extend(): void
    {
        try {
            $sub = $this->subscription();
            if ($sub->status === MembershipSubscription::STATUS_CANCELLED) {
                throw new RuntimeException('Cancelled subscriptions can\'t be extended.');
            }
            if ($this->extendDays === null || $this->extendDays <= 0) {
                throw new RuntimeException('Enter how many days to add (must be greater than 0).');
            }
            if ($this->extendAmount === null || $this->extendAmount < 0) {
                throw new RuntimeException('Enter a payment amount (₦0 for a comp extension).');
            }
            if (! in_array($this->extendMethod, ['cash', 'transfer', 'card', 'pos', 'other'], true)) {
                throw new RuntimeException('Pick a payment method.');
            }

            $operator = auth('web')->user();
            $now = Carbon::now();

            DB::transaction(function () use ($sub, $operator, $now): void {
                $baseline = $sub->expires_at->greaterThan($now) ? $sub->expires_at : $now;
                $newExpires = $baseline->copy()->addDays((int) $this->extendDays);

                $sub->forceFill([
                    'expires_at' => $newExpires,
                    'status'     => MembershipSubscription::STATUS_ACTIVE,
                ])->save();

                if ((int) $this->extendAmount > 0) {
                    Payment::query()->create([
                        'membership_subscription_id' => $sub->id,
                        'paystack_reference'         => Members::freshPaymentRef(),
                        'amount'                     => (int) $this->extendAmount,
                        'type'                       => PaymentType::Extra,
                        'method'                     => $this->extendMethod,
                        'status'                     => PaymentStatus::Succeeded,
                        'membership_kind'            => MembershipPayment::KIND_EXTENSION,
                        'membership_period_start'    => $baseline,
                        'membership_period_end'      => $newExpires,
                        'reference_note'             => (int) $this->extendDays.' day extension',
                        'collected_by_operator_id'   => $operator?->getKey(),
                        'paid_at'                    => $now,
                    ]);
                }
            });

            $this->extendOpen = false;
            $this->dispatch('notify',
                title: 'Extended',
                message: 'Membership extended to '.$this->subscription()->refresh()->expires_at->toFormattedDateString().'.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t extend', message: $e->getMessage(), kind: 'err');
        }
    }

    // ── Take payment ──────────────────────────────────────────────

    public function togglePayment(): void
    {
        $this->paymentOpen = ! $this->paymentOpen;
        if ($this->paymentOpen) {
            $this->paymentAmount = null;
            $this->paymentMethod = 'cash';
            $this->paymentNote = '';
        }
    }

    public function takePayment(): void
    {
        try {
            $sub = $this->subscription();
            if ($this->paymentAmount === null || $this->paymentAmount <= 0) {
                throw new RuntimeException('Enter an amount above ₦0.');
            }
            if (! in_array($this->paymentMethod, ['cash', 'transfer', 'card', 'pos', 'other'], true)) {
                throw new RuntimeException('Pick a payment method.');
            }

            $operator = auth('web')->user();

            $note = trim($this->paymentNote) !== '' ? trim($this->paymentNote) : null;
            Payment::query()->create([
                'membership_subscription_id' => $sub->id,
                'paystack_reference'         => Members::freshPaymentRef(),
                'amount'                     => (int) $this->paymentAmount,
                'type'                       => PaymentType::Extra,
                'method'                     => $this->paymentMethod,
                'status'                     => PaymentStatus::Succeeded,
                'membership_kind'            => MembershipPayment::KIND_OTHER,
                'reference_note'             => $note,
                'collected_by_operator_id'   => $operator?->getKey(),
                'paid_at'                    => Carbon::now(),
            ]);

            $this->paymentOpen = false;
            $this->paymentAmount = null;
            $this->paymentNote = '';
            $this->dispatch('notify',
                title: 'Payment recorded',
                message: '₦'.number_format((int) $sub->refresh()->payments()->latest('id')->first()?->amount ?? 0)
                    .' via '.$this->paymentMethod.'.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t record', message: $e->getMessage(), kind: 'err');
        }
    }

    // ── Cancel ────────────────────────────────────────────────────

    public function toggleCancel(): void
    {
        $this->cancelOpen = ! $this->cancelOpen;
        if ($this->cancelOpen) {
            $this->cancelReason = '';
        }
    }

    public function cancel(): void
    {
        try {
            $sub = $this->subscription();
            if ($sub->status === MembershipSubscription::STATUS_CANCELLED) {
                throw new RuntimeException('Already cancelled.');
            }
            $sub->forceFill([
                'status'              => MembershipSubscription::STATUS_CANCELLED,
                'cancelled_at'        => Carbon::now(),
                'cancellation_reason' => trim($this->cancelReason) !== '' ? trim($this->cancelReason) : null,
            ])->save();
            $this->cancelOpen = false;
            $this->dispatch('notify',
                title: 'Membership cancelled',
                message: 'The member\'s access is revoked immediately.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t cancel', message: $e->getMessage(), kind: 'err');
        }
    }

    public function render(): View
    {
        $sub = $this->subscription();
        $payments = $sub->payments()->with('collectedBy')->orderByDesc('paid_at')->get();
        $totalPaid = (int) $payments->where('status', MembershipPayment::STATUS_SUCCEEDED)->sum('amount');

        $planOptions = MembershipPlan::query()
            ->where('business_id', $sub->business_id)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.dashboard.membership.show', [
            'sub'         => $sub,
            'payments'    => $payments,
            'totalPaid'   => $totalPaid,
            'planOptions' => $planOptions,
        ]);
    }

    private function subscription(): MembershipSubscription
    {
        return MembershipSubscription::query()
            ->with(['plan.business', 'business', 'customer', 'createdByOperator'])
            ->findOrFail($this->subscriptionId);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Membership;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Customer;
use App\Models\MembershipBusiness;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\MembershipSubscription;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
use Throwable;

/**
 * Members index. List every subscription with status + expiry;
 * inline "Subscribe" form lets the operator sign a walk-in up in
 * one round-trip: pick customer + plan, set start date, take the
 * initial payment.
 */
#[Layout('layouts.tenant-auth')]
class Members extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'biz', except: '')]
    public string $businessFilter = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';   // all | active | expired | cancelled

    // Subscribe form
    public bool $subscribeOpen = false;

    public string $customerSearch = '';

    public ?int $customerId = null;

    public ?int $businessId = null;

    public ?int $planId = null;

    public string $startDate = '';

    public ?int $paymentAmount = null;

    public string $paymentMethod = 'cash';

    public function updatedSearch(): void { $this->resetPage(); }

    public function updatedBusinessFilter(): void { $this->resetPage(); }

    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function toggleSubscribe(): void
    {
        $this->subscribeOpen = ! $this->subscribeOpen;
        if ($this->subscribeOpen) {
            $this->reset(['customerSearch', 'customerId', 'businessId', 'planId', 'paymentAmount']);
            $this->paymentMethod = 'cash';
            $this->startDate = Carbon::today()->toDateString();
        }
    }

    public function updatedBusinessId(): void
    {
        $this->planId = null;
        $this->paymentAmount = null;
    }

    public function updatedPlanId(): void
    {
        // Auto-fill the amount from the plan's price to save a step,
        // operator can override if the guest negotiated a lower one.
        if ($this->planId !== null) {
            $plan = MembershipPlan::query()->find($this->planId);
            if ($plan !== null) {
                $this->paymentAmount = $plan->price;
            }
        }
    }

    public function subscribe(): void
    {
        try {
            if ($this->customerId === null) {
                throw new RuntimeException('Pick a customer first.');
            }
            if ($this->planId === null) {
                throw new RuntimeException('Pick a plan.');
            }
            $plan = MembershipPlan::query()->find($this->planId);
            if ($plan === null || ! $plan->active) {
                throw new RuntimeException('Plan not found or inactive.');
            }
            $customer = Customer::query()->find($this->customerId);
            if ($customer === null) {
                throw new RuntimeException('Customer not found.');
            }
            $start = $this->startDate !== ''
                ? Carbon::parse($this->startDate)->startOfDay()
                : Carbon::today();
            if ($this->paymentAmount === null || $this->paymentAmount < 0) {
                throw new RuntimeException('Enter a payment amount (₦0 = no charge).');
            }
            if (! in_array($this->paymentMethod, ['cash', 'transfer', 'card', 'pos', 'other'], true)) {
                throw new RuntimeException('Pick a payment method.');
            }

            $operator = auth('web')->user();

            DB::transaction(function () use ($plan, $customer, $start, $operator): void {
                $expires = $start->copy()->addDays($plan->validity_days);
                $ref = self::freshMemberRef();

                $sub = MembershipSubscription::query()->create([
                    'business_id'             => $plan->business_id,
                    'plan_id'                 => $plan->id,
                    'customer_id'             => $customer->id,
                    'member_ref'              => $ref,
                    'started_at'              => $start,
                    'expires_at'              => $expires,
                    'status'                  => MembershipSubscription::STATUS_ACTIVE,
                    'created_by_operator_id'  => $operator?->getKey(),
                ]);

                if ((int) $this->paymentAmount > 0) {
                    Payment::query()->create([
                        'membership_subscription_id' => $sub->id,
                        'paystack_reference'         => self::freshPaymentRef(),
                        'amount'                     => (int) $this->paymentAmount,
                        'type'                       => PaymentType::Extra,
                        'method'                     => $this->paymentMethod,
                        'status'                     => PaymentStatus::Succeeded,
                        'membership_kind'            => MembershipPayment::KIND_INITIAL,
                        'membership_period_start'    => $start,
                        'membership_period_end'      => $expires,
                        'reference_note'             => 'Membership: '.$plan->name,
                        'collected_by_operator_id'   => $operator?->getKey(),
                        'paid_at'                    => Carbon::now(),
                    ]);
                }
            });

            $this->subscribeOpen = false;
            $this->reset(['customerId', 'customerSearch', 'businessId', 'planId', 'paymentAmount']);
            $this->dispatch('notify',
                title: 'Member signed up',
                message: $customer->name.' subscribed to '.$plan->name.'.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t subscribe', message: $e->getMessage(), kind: 'err');
        }
    }

    public function render(): View
    {
        $now = Carbon::now();
        $query = MembershipSubscription::query()->with(['plan', 'business', 'customer']);

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('member_ref', 'like', $like)
                  ->orWhereHas('customer', function (Builder $c) use ($like): void {
                      $c->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                  });
            });
        }
        if ($this->businessFilter !== '') {
            $query->where('business_id', (int) $this->businessFilter);
        }
        match ($this->statusFilter) {
            'active'    => $query->where('status', MembershipSubscription::STATUS_ACTIVE)
                                 ->where('expires_at', '>', $now),
            'expired'   => $query->where(function (Builder $q) use ($now): void {
                                    $q->where('status', MembershipSubscription::STATUS_EXPIRED)
                                      ->orWhere(function (Builder $qq) use ($now): void {
                                          $qq->where('status', MembershipSubscription::STATUS_ACTIVE)
                                             ->where('expires_at', '<=', $now);
                                      });
                                }),
            'cancelled' => $query->where('status', MembershipSubscription::STATUS_CANCELLED),
            default     => null,
        };

        $subs = $query->orderByDesc('id')->paginate(20);

        $businesses = MembershipBusiness::query()->orderBy('name')->get();

        $customerMatches = collect();
        if ($this->subscribeOpen && trim($this->customerSearch) !== '' && $this->customerId === null) {
            $like = '%'.trim($this->customerSearch).'%';
            $customerMatches = Customer::query()
                ->where(function (Builder $q) use ($like): void {
                    $q->where('name', 'like', $like)
                      ->orWhere('phone', 'like', $like)
                      ->orWhere('email', 'like', $like);
                })
                ->orderBy('name')
                ->limit(8)
                ->get();
        }

        $pickedCustomer = $this->customerId !== null
            ? Customer::query()->find($this->customerId)
            : null;

        $planOptions = $this->businessId !== null
            ? MembershipPlan::query()
                ->where('business_id', $this->businessId)
                ->where('active', true)
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.dashboard.membership.members', [
            'subs'            => $subs,
            'businesses'      => $businesses,
            'customerMatches' => $customerMatches,
            'pickedCustomer'  => $pickedCustomer,
            'planOptions'     => $planOptions,
        ]);
    }

    public static function freshMemberRef(): string
    {
        return 'MB-'.strtoupper(Str::random(6));
    }

    public static function freshPaymentRef(): string
    {
        return 'MP-'.strtoupper(Str::random(8));
    }
}

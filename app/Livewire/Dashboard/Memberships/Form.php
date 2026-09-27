<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Memberships;

use App\Enums\BillingInterval;
use App\Enums\MembershipStatus;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Workspace;
use App\Models\WorkspacePlan;
use App\Services\Corporates\CorporateBookingService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    #[Locked]
    public ?int $membershipId = null;

    public ?int $customer_id = null;

    public string $customer_name = '';

    public string $customer_email = '';

    public string $customer_phone = '';

    public ?int $workspace_plan_id = null;

    public ?int $workspace_id = null;

    public string $status = 'active';

    public string $started_at = '';

    public ?string $next_renewal_at = null;

    public ?string $expires_at = null;

    public int $amount_naira = 0;

    public string $billing_interval = 'month';

    public string $paystack_subscription_code = '';

    public string $notes = '';

    public ?int $corporate_id = null;

    public ?int $corporate_member_id = null;

    public function updatedCorporateId(): void
    {
        $this->corporate_member_id = null;
    }

    public function mount(?Membership $membership = null): void
    {
        $this->started_at = now()->toDateString();
        $this->next_renewal_at = now()->addMonth()->toDateString();

        if ($membership !== null && $membership->exists) {
            $this->membershipId = $membership->id;
            $this->customer_id = $membership->customer_id;
            $cust = $membership->customer;
            $this->customer_name = (string) $cust?->name;
            $this->customer_email = (string) $cust?->email;
            $this->customer_phone = (string) $cust?->phone;
            $this->workspace_plan_id = $membership->workspace_plan_id;
            $this->workspace_id = $membership->workspace_id;
            $this->status = $membership->status->value;
            $this->started_at = $membership->started_at?->toDateString() ?? '';
            $this->next_renewal_at = $membership->next_renewal_at?->toDateString();
            $this->expires_at = $membership->expires_at?->toDateString();
            $this->amount_naira = $membership->amount_naira;
            $this->billing_interval = $membership->billing_interval->value;
            $this->paystack_subscription_code = (string) $membership->paystack_subscription_code;
            $this->notes = (string) $membership->notes;
            $this->corporate_id = $membership->corporate_id;
            $this->corporate_member_id = $membership->corporate_member_id;
        }
    }

    public function updatedWorkspacePlanId(?int $id): void
    {
        if ($id === null) {
            return;
        }
        $plan = WorkspacePlan::query()->find($id);
        if ($plan !== null) {
            $this->billing_interval = $plan->billing_interval->value;
            $this->amount_naira = $plan->price_naira;
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function rules(): array
    {
        return [
            'customer_name'              => ['required_without:customer_id', 'string', 'max:120'],
            'customer_email'             => ['nullable', 'email', 'max:160'],
            'customer_phone'             => ['nullable', 'string', 'max:32'],
            'customer_id'                => ['nullable', 'integer', 'exists:customers,id'],
            'workspace_plan_id'          => ['required', 'integer', 'exists:workspace_plans,id'],
            'workspace_id'               => ['nullable', 'integer', 'exists:workspaces,id'],
            'status'                     => ['required', Rule::in(array_map(fn ($c) => $c->value, MembershipStatus::cases()))],
            'started_at'                 => ['required', 'date'],
            'next_renewal_at'            => ['nullable', 'date'],
            'expires_at'                 => ['nullable', 'date'],
            'amount_naira'               => ['required', 'integer', 'min:0'],
            'billing_interval'           => ['required', Rule::in(array_map(fn ($c) => $c->value, BillingInterval::cases()))],
            'paystack_subscription_code' => ['nullable', 'string', 'max:64'],
            'notes'                      => ['nullable', 'string', 'max:5000'],
            'corporate_id'               => ['nullable', 'integer', 'exists:corporates,id'],
            'corporate_member_id'        => ['nullable', 'integer', 'exists:corporate_members,id'],
        ];
    }

    public function save(CorporateBookingService $corporates): void
    {
        $data = $this->validate();

        $customer = $this->resolveCustomer();
        if ($customer === null) {
            $this->addError('customer_name', 'Customer is required.');

            return;
        }

        $membership = $this->membershipId !== null
            ? Membership::query()->findOrFail($this->membershipId)
            : new Membership;

        $membership->fill([
            'customer_id'                => $customer->id,
            'workspace_plan_id'          => $data['workspace_plan_id'],
            'workspace_id'               => $data['workspace_id'] ?? null,
            'status'                     => $data['status'],
            'started_at'                 => $data['started_at'],
            'next_renewal_at'            => $data['next_renewal_at'] ?? null,
            'expires_at'                 => $data['expires_at'] ?? null,
            'amount_naira'               => $data['amount_naira'],
            'billing_interval'           => $data['billing_interval'],
            'paystack_subscription_code' => $data['paystack_subscription_code'] ?: null,
            'notes'                      => $data['notes'] ?: null,
        ])->save();

        if ($this->membershipId === null && ! empty($data['corporate_id'])) {
            try {
                $corporate = Corporate::query()->findOrFail((int) $data['corporate_id']);
                $member = ! empty($data['corporate_member_id'])
                    ? CorporateMember::query()->find((int) $data['corporate_member_id'])
                    : null;
                if ($member !== null && $member->corporate_id !== $corporate->id) {
                    $this->addError('corporate_member_id', 'Member does not belong to the selected corporate.');

                    return;
                }
                $corporates->attachToMembership($membership->refresh(), $corporate, $member);
            } catch (RuntimeException $e) {
                $this->addError('corporate_id', $e->getMessage());

                return;
            }
        }

        session()->flash('status', $this->membershipId ? 'Membership updated.' : 'Membership created.');
        $this->redirectRoute('tenant.memberships.index', navigate: true);
    }

    private function resolveCustomer(): ?Customer
    {
        // Per-branch CRM (see DeskBookings\Form).
        if ($this->customer_id !== null) {
            return Customer::query()->forCurrentProperty()->find($this->customer_id);
        }
        if ($this->customer_name === '') {
            return null;
        }
        $existing = null;
        if ($this->customer_email !== '') {
            $existing = Customer::query()->forCurrentProperty()
                ->where('email', $this->customer_email)->first();
        }
        if ($existing === null && $this->customer_phone !== '') {
            $existing = Customer::query()->forCurrentProperty()
                ->where('phone', $this->customer_phone)->first();
        }
        if ($existing !== null) {
            return $existing;
        }

        return Customer::query()->create([
            'name'  => $this->customer_name,
            'email' => $this->customer_email ?: null,
            'phone' => $this->customer_phone ?: null,
        ]);
    }

    public function render(): View
    {
        $corpList = Corporate::query()->active()->orderBy('name')->get();
        $members = $this->corporate_id !== null
            ? CorporateMember::query()->active()
                ->where('corporate_id', $this->corporate_id)
                ->orderBy('name')->get()
            : collect();

        return view('livewire.dashboard.memberships.form', [
            'plans'      => WorkspacePlan::query()->active()->orderBy('name')->get(),
            'workspaces' => Workspace::query()->active()->orderBy('name')->get(),
            'statuses'   => MembershipStatus::cases(),
            'intervals'  => BillingInterval::cases(),
            'isEdit'     => $this->membershipId !== null,
            'corporates' => $corpList,
            'corporateMembers' => $members,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Leases;

use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\Customer;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\Corporates\CorporateBookingService;
use App\Services\Leases\RentSchedule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $leaseId = null;

    public ?int $unit_id = null;

    public ?int $customer_id = null;

    public string $customer_name = '';

    public string $customer_email = '';

    public string $customer_phone = '';

    public string $started_at = '';

    public string $expires_at = '';

    public int $term_months = 12;

    public int $annual_rent_naira = 0;

    public ?int $monthly_rent_naira = null;

    public int $deposit_naira = 0;

    public int $service_charge_naira = 0;

    public int $renewal_reminder_days = 60;

    public string $status = 'pending';

    public string $notes = '';

    public $document = null;

    public ?string $existingDocumentPath = null;

    public ?int $corporate_id = null;

    public ?int $corporate_member_id = null;

    public function updatedCorporateId(): void
    {
        $this->corporate_member_id = null;
    }

    public function mount(?Lease $lease = null): void
    {
        $this->started_at = now()->toDateString();
        $this->expires_at = now()->addYear()->toDateString();

        if ($lease !== null && $lease->exists) {
            $this->leaseId = $lease->id;
            $this->unit_id = $lease->unit_id;
            $this->customer_id = $lease->customer_id;
            $cust = $lease->customer;
            $this->customer_name = (string) $cust?->name;
            $this->customer_email = (string) $cust?->email;
            $this->customer_phone = (string) $cust?->phone;
            $this->started_at = $lease->started_at->toDateString();
            $this->expires_at = $lease->expires_at->toDateString();
            $this->term_months = $lease->term_months;
            $this->annual_rent_naira = $lease->annual_rent_naira;
            $this->monthly_rent_naira = $lease->monthly_rent_naira;
            $this->deposit_naira = $lease->deposit_naira;
            $this->service_charge_naira = $lease->service_charge_naira;
            $this->renewal_reminder_days = $lease->renewal_reminder_days;
            $this->status = $lease->status->value;
            $this->notes = (string) $lease->notes;
            $this->existingDocumentPath = $lease->document_path;
            $this->corporate_id = $lease->corporate_id;
            $this->corporate_member_id = $lease->corporate_member_id;
        }
    }

    public function updatedUnitId(?int $id): void
    {
        if ($id === null) {
            return;
        }
        $unit = Unit::query()->find($id);
        if ($unit === null) {
            return;
        }
        if ($this->annual_rent_naira === 0) {
            $this->annual_rent_naira = $unit->annual_rent_naira;
        }
        if ($this->monthly_rent_naira === null && $unit->monthly_rent_naira !== null) {
            $this->monthly_rent_naira = $unit->monthly_rent_naira;
        }
        if ($this->deposit_naira === 0) {
            $this->deposit_naira = $unit->deposit_naira;
        }
        if ($this->service_charge_naira === 0) {
            $this->service_charge_naira = $unit->service_charge_annual_naira;
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function rules(): array
    {
        return [
            'unit_id'               => ['required', 'integer', 'exists:units,id'],
            'customer_name'         => ['required_without:customer_id', 'string', 'max:120'],
            'customer_email'        => ['nullable', 'email', 'max:160'],
            'customer_phone'        => ['nullable', 'string', 'max:32'],
            'customer_id'           => ['nullable', 'integer', 'exists:customers,id'],
            'started_at'            => ['required', 'date'],
            'expires_at'            => ['required', 'date', 'after:started_at'],
            'term_months'           => ['required', 'integer', 'min:1', 'max:120'],
            'annual_rent_naira'     => ['required', 'integer', 'min:0'],
            'monthly_rent_naira'    => ['nullable', 'integer', 'min:0'],
            'deposit_naira'         => ['nullable', 'integer', 'min:0'],
            'service_charge_naira'  => ['nullable', 'integer', 'min:0'],
            'renewal_reminder_days' => ['required', 'integer', 'min:0', 'max:365'],
            'status'                => ['required', Rule::in(array_map(fn ($c) => $c->value, LeaseStatus::cases()))],
            'notes'                 => ['nullable', 'string', 'max:8000'],
            'document'              => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'corporate_id'          => ['nullable', 'integer', 'exists:corporates,id'],
            'corporate_member_id'   => ['nullable', 'integer', 'exists:corporate_members,id'],
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

        $lease = $this->leaseId !== null
            ? Lease::query()->findOrFail($this->leaseId)
            : new Lease;

        if (! $lease->exists) {
            $lease->reference = 'L-'.strtoupper(Str::random(8));
        }

        $documentPath = $this->existingDocumentPath;
        if ($this->document !== null) {
            $documentPath = $this->document->store('lease-documents', 'public');
        }

        $lease->fill([
            'unit_id'                => $data['unit_id'],
            'customer_id'            => $customer->id,
            'started_at'             => $data['started_at'],
            'expires_at'             => $data['expires_at'],
            'term_months'            => $data['term_months'],
            'annual_rent_naira'      => $data['annual_rent_naira'],
            'monthly_rent_naira'     => $data['monthly_rent_naira'] ?? null,
            'deposit_naira'          => $data['deposit_naira'] ?? 0,
            'service_charge_naira'   => $data['service_charge_naira'] ?? 0,
            'renewal_reminder_days'  => $data['renewal_reminder_days'],
            'status'                 => $data['status'],
            'notes'                  => $data['notes'] ?: null,
            'document_path'          => $documentPath,
            'signed_at'              => in_array($data['status'], ['active'], true)
                ? ($lease->signed_at ?? now())
                : $lease->signed_at,
        ])->save();

        // Generate the rent collection schedule. Idempotent, won't
        // duplicate rows on re-save.
        (new RentSchedule)->regenerate($lease->refresh());

        // Corporate attribution + first-year wallet hold. Skip on edits
        // so the existing ledger entry isn't orphaned.
        if ($this->leaseId === null && ! empty($data['corporate_id'])) {
            try {
                $corporate = Corporate::query()->findOrFail((int) $data['corporate_id']);
                $member = ! empty($data['corporate_member_id'])
                    ? CorporateMember::query()->find((int) $data['corporate_member_id'])
                    : null;
                if ($member !== null && $member->corporate_id !== $corporate->id) {
                    $this->addError('corporate_member_id', 'Member does not belong to the selected corporate.');

                    return;
                }
                $corporates->attachToLease($lease->refresh(), $corporate, $member);
            } catch (RuntimeException $e) {
                $this->addError('corporate_id', $e->getMessage());

                return;
            }
        }

        // Pending → Active flip on an existing corporate lease captures
        // the first-year hold (signed lease + occupancy starts).
        if ($this->leaseId !== null && $lease->corporate_id !== null && $data['status'] === LeaseStatus::Active->value) {
            $corporates->captureForLease($lease->refresh());
        }

        // Flip the unit's status to mirror the lease.
        $unit = Unit::query()->find($lease->unit_id);
        if ($unit !== null) {
            if (in_array($lease->status->value, ['active', 'pending'], true)) {
                $unit->update(['status' => UnitStatus::Leased->value]);
            } elseif (in_array($lease->status->value, ['terminated', 'expired'], true)) {
                $unit->update(['status' => UnitStatus::Available->value]);
            }
        }

        session()->flash('status', $this->leaseId ? 'Lease updated.' : 'Lease created.');
        $this->redirectRoute('tenant.leases.index', navigate: true);
    }

    private function resolveCustomer(): ?Customer
    {
        // Per-branch CRM: match + create scoped to the current property.
        // See DeskBookings\Form for the full reasoning.
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

        return view('livewire.dashboard.leases.form', [
            'units'    => Unit::query()->whereIn('status', ['available', 'leased'])->orderBy('name')->get(),
            'statuses' => LeaseStatus::cases(),
            'isEdit'   => $this->leaseId !== null,
            'corporates' => $corpList,
            'corporateMembers' => $members,
        ]);
    }
}

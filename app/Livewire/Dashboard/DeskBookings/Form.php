<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\DeskBookings;

use App\Enums\DeskBookingStatus;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\Customer;
use App\Models\DeskBooking;
use App\Models\Workspace;
use App\Services\Corporates\CorporateBookingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    #[Locked]
    public ?int $bookingId = null;

    public ?int $workspace_id = null;

    public ?int $customer_id = null;

    public string $customer_name = '';

    public string $customer_email = '';

    public string $customer_phone = '';

    public string $start_at = '';

    public string $end_at = '';

    public int $amount_naira = 0;

    public string $status = 'confirmed';

    public string $notes = '';

    // Corporate attribution (optional). When set, the booking is paid
    // from the corporate's wallet at the negotiated rate.
    public ?int $corporate_id = null;

    public ?int $corporate_member_id = null;

    public function mount(?DeskBooking $deskBooking = null): void
    {
        $this->start_at = now()->copy()->startOfHour()->addHour()->format('Y-m-d\TH:i');
        $this->end_at = now()->copy()->startOfHour()->addHours(2)->format('Y-m-d\TH:i');

        if ($deskBooking !== null && $deskBooking->exists) {
            $this->bookingId = $deskBooking->id;
            $this->workspace_id = $deskBooking->workspace_id;
            $this->customer_id = $deskBooking->customer_id;
            $cust = $deskBooking->customer;
            $this->customer_name = (string) $cust?->name;
            $this->customer_email = (string) $cust?->email;
            $this->customer_phone = (string) $cust?->phone;
            $this->start_at = $deskBooking->start_at->format('Y-m-d\TH:i');
            $this->end_at = $deskBooking->end_at->format('Y-m-d\TH:i');
            $this->amount_naira = $deskBooking->amount_naira;
            $this->status = $deskBooking->status->value;
            $this->notes = (string) $deskBooking->notes;
            $this->corporate_id = $deskBooking->corporate_id;
            $this->corporate_member_id = $deskBooking->corporate_member_id;
        }
    }

    /**
     * When the operator picks a corporate, clear any selected member
     * so they don't end up attributing the booking to a member who
     * belongs to a different company.
     */
    public function updatedCorporateId(): void
    {
        $this->corporate_member_id = null;
    }

    public function updatedWorkspaceId(?int $id): void
    {
        if ($id === null || $this->amount_naira > 0) {
            return;
        }
        $w = Workspace::query()->find($id);
        if ($w !== null) {
            // Auto-fill amount from hourly rate * hours, or fall back to daily rate.
            $hours = max(1, (int) Carbon::parse($this->start_at)->diffInHours(Carbon::parse($this->end_at)));
            $this->amount_naira = $w->hourly_rate_naira
                ? $w->hourly_rate_naira * $hours
                : (int) ($w->daily_rate_naira ?? 0);
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function rules(): array
    {
        return [
            'workspace_id'    => ['required', 'integer', 'exists:workspaces,id'],
            'customer_name'   => ['required_without:customer_id', 'string', 'max:120'],
            'customer_email'  => ['nullable', 'email', 'max:160'],
            'customer_phone'  => ['nullable', 'string', 'max:32'],
            'customer_id'     => ['nullable', 'integer', 'exists:customers,id'],
            'start_at'        => ['required', 'date'],
            'end_at'          => ['required', 'date', 'after:start_at'],
            'amount_naira'    => ['required', 'integer', 'min:0'],
            'status'          => ['required', Rule::in(array_map(fn ($c) => $c->value, DeskBookingStatus::cases()))],
            'notes'           => ['nullable', 'string', 'max:5000'],
            'corporate_id'        => ['nullable', 'integer', 'exists:corporates,id'],
            'corporate_member_id' => ['nullable', 'integer', 'exists:corporate_members,id'],
        ];
    }

    public function save(CorporateBookingService $corporates): void
    {
        $data = $this->validate();

        $customer = $this->resolveCustomer();

        $booking = $this->bookingId !== null
            ? DeskBooking::query()->findOrFail($this->bookingId)
            : new DeskBooking;

        if (! $booking->exists) {
            $booking->reference = 'D-'.strtoupper(Str::random(8));
        }

        $booking->fill([
            'workspace_id' => $data['workspace_id'],
            'customer_id'  => $customer?->id,
            'start_at'     => $data['start_at'],
            'end_at'       => $data['end_at'],
            'amount_naira' => $data['amount_naira'],
            'status'       => $data['status'],
            'notes'        => $data['notes'] ?: null,
        ])->save();

        // Corporate attribution + wallet hold. Only fires on create -
        // changing the corporate on an existing booking is intentionally
        // not allowed (it'd orphan the existing ledger entry).
        if ($this->bookingId === null && ! empty($data['corporate_id'])) {
            try {
                $corporate = Corporate::query()->findOrFail((int) $data['corporate_id']);
                $member = ! empty($data['corporate_member_id'])
                    ? CorporateMember::query()->find((int) $data['corporate_member_id'])
                    : null;
                // Ensure the picked member actually belongs to the picked corporate.
                if ($member !== null && $member->corporate_id !== $corporate->id) {
                    $this->addError('corporate_member_id', 'Member does not belong to the selected corporate.');

                    return;
                }
                $corporates->attachToDeskBooking($booking->refresh(), $corporate, $member);
            } catch (RuntimeException $e) {
                $this->addError('corporate_id', $e->getMessage());

                return;
            }
        }

        session()->flash('status', $this->bookingId ? 'Booking updated.' : 'Desk booking created.');
        $this->redirectRoute('tenant.desk-bookings.index', navigate: true);
    }

    private function resolveCustomer(): ?Customer
    {
        // Explicit customer_id picked from the search box — trust it
        // but still guard on the current branch (Phase 4 authZ).
        if ($this->customer_id !== null) {
            return Customer::query()->forCurrentProperty()->find($this->customer_id);
        }
        if ($this->customer_name === '') {
            return null;
        }
        // Match existing customers scoped to the current branch. A
        // guest who's also stayed at a sister branch under this tenant
        // gets a fresh Customer row here — each branch owns its own
        // CRM view per Phase 4.
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

        // BelongsToProperty auto-stamps property_id from
        // PropertyContext::current() via the creating() hook.
        return Customer::query()->create([
            'name'  => $this->customer_name,
            'email' => $this->customer_email ?: null,
            'phone' => $this->customer_phone ?: null,
        ]);
    }

    public function render(): View
    {
        // Active corporates available for attribution. We only expose
        // those whose status is Active, onboarding/suspended would
        // create stale data the operator can't action.
        $corporates = Corporate::query()->active()->orderBy('name')->get();
        $members = $this->corporate_id !== null
            ? CorporateMember::query()->active()
                ->where('corporate_id', $this->corporate_id)
                ->orderBy('name')->get()
            : collect();

        return view('livewire.dashboard.desk-bookings.form', [
            'workspaces' => Workspace::query()->active()->orderBy('name')->get(),
            'statuses'   => DeskBookingStatus::cases(),
            'isEdit'     => $this->bookingId !== null,
            'corporates' => $corporates,
            'corporateMembers' => $members,
        ]);
    }
}

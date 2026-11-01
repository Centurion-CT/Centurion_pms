<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Corporates;

use App\Models\Booking;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\DeskBooking;
use App\Models\Lease;
use App\Models\Membership;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Single-corporate operator view. Only renders if the corporate has
 * actually done business at this tenant, otherwise 404, so an
 * operator can't enumerate every corporate on the platform by
 * guessing slugs.
 */
#[Layout('layouts.tenant-auth')]
class Show extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $corporateId;

    // ── Members management (this-tenant-owned corps only) ────────
    public bool $memberFormOpen = false;

    #[Locked]
    public ?int $editingMemberId = null;

    public string $memberName = '';

    public string $memberEmail = '';

    public string $memberPhone = '';

    public string $memberEmployeeId = '';

    public string $memberRole = 'member';   // admin | member

    // ── Category rate form ────────────────────────────────────────
    public bool $categoryRateFormOpen = false;

    #[Locked]
    public ?int $editingCategoryRateId = null;

    public ?int $categoryRateCategoryId = null;

    public string $categoryRateType = 'percent_off';    // percent_off|fixed_off|fixed_price

    public ?int $categoryRateValue = null;

    public ?int $categoryRateMinNights = null;

    public string $categoryRateNotes = '';

    // ── Length-of-stay discount form ──────────────────────────────
    public bool $losFormOpen = false;

    #[Locked]
    public ?int $editingLosId = null;

    public ?int $losMinNights = null;

    public ?int $losPercentOff = null;

    public string $losNotes = '';

    // ── Corporate reservation form ────────────────────────────────
    public bool $reservationFormOpen = false;

    #[Locked]
    public ?int $editingReservationId = null;

    public ?int $reservationPropertyId = null;

    public string $reservationStartsAt = '';

    public string $reservationEndsAt = '';

    /** @var array<int, array{room_category_id: ?int, rooms_count: int}> */
    public array $reservationCategoryRows = [];

    /** @var array<int, int> corporate_member_ids */
    public array $reservationMemberIds = [];

    public string $reservationNotes = '';

    /**
     * Which config panel is on screen. Only the picked one renders,
     * keeping the page compact even on corps with dozens of members +
     * rate rules. Options: `staff` | `rates` | `los` | `reservations`.
     * Defaults to `staff` because it's the most common opener move
     * (add staff so the corp check-in path lights up).
     */
    public string $activePanel = 'staff';

    public function updatedActivePanel(string $value): void
    {
        // Close any inline form the operator had open in the previous
        // panel — landing on a new panel with a stale form open is
        // confusing (they'd think they were about to submit against
        // the currently-visible entity).
        $this->memberFormOpen = false;
        $this->categoryRateFormOpen = false;
        $this->losFormOpen = false;
        $this->reservationFormOpen = false;
    }

    // ── Ledger: record payment form ───────────────────────────────
    public bool $paymentFormOpen = false;

    public ?int $paymentAmount = null;

    public string $paymentMethod = 'transfer';

    public string $paymentReference = '';

    public string $paymentPaidAt = '';

    public string $paymentNote = '';

    public $paymentReceipt = null;

    /**
     * Set by openPaymentForInvoice() — the specific ledger row the
     * operator clicked "Pay" on. RecordCorporatePayment settles it
     * before any FIFO fallback. Cleared on close.
     */
    public ?int $paymentTargetInvoiceId = null;

    // ── Wallet: top-up drawer ─────────────────────────────────────
    public bool $topupFormOpen = false;

    public ?int $topupAmount = null;

    /** 'paystack' → Paystack init returns a hosted checkout URL.
     *  'manual'   → Records that the operator received the funds
     *               (cash / bank transfer) off-platform; wallet
     *               credits immediately. */
    public string $topupSource = 'paystack';

    public string $topupMethod = 'transfer';

    public string $topupReference = '';

    public string $topupNote = '';

    // ── Consolidated corporate invoice form ───────────────────────
    public bool $consolidatedFormOpen = false;

    public string $consolidatedFrom = '';

    public string $consolidatedTo = '';

    public string $consolidatedNotes = '';

    public function mount(Corporate $corporate): void
    {
        $tenantId = (int) (Tenant::current()?->id ?? 0);

        if ($tenantId === 0) {
            throw new NotFoundHttpException;
        }

        // Two paths to access:
        //   (a) this tenant's Manager registered the corp themselves
        //       (created_by_tenant_id). Newly-created corps have no
        //       activity yet but Managers still need to profile the
        //       staff, so we open the view to them.
        //   (b) the corp has actual business here — cheapest path is
        //       a UNION existence check across the four subject tables.
        $isOwnedByThisTenant = (int) $corporate->created_by_tenant_id === $tenantId;
        $hasActivity = $isOwnedByThisTenant
            || Booking::query()->where('corporate_id', $corporate->id)->exists()
            || DeskBooking::query()->where('corporate_id', $corporate->id)->exists()
            || Membership::query()->where('corporate_id', $corporate->id)->exists()
            || Lease::query()->where('corporate_id', $corporate->id)->exists();

        if (! $hasActivity) {
            throw new NotFoundHttpException;
        }

        $this->corporateId = $corporate->id;
    }

    // ── Members management actions ───────────────────────────────

    public function newMember(): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        $this->editingMemberId = null;
        $this->memberName = '';
        $this->memberEmail = '';
        $this->memberPhone = '';
        $this->memberEmployeeId = '';
        $this->memberRole = 'member';
        $this->memberFormOpen = true;
    }

    public function editMember(int $id): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        $member = CorporateMember::query()
            ->where('corporate_id', $this->corporateId)
            ->find($id);
        if ($member === null) {
            return;
        }
        $this->editingMemberId = $member->id;
        $this->memberName = (string) $member->name;
        $this->memberEmail = (string) $member->email;
        $this->memberPhone = (string) $member->phone;
        $this->memberEmployeeId = (string) $member->employee_id;
        $this->memberRole = (string) $member->role->value;
        $this->memberFormOpen = true;
    }

    public function closeMemberForm(): void
    {
        $this->memberFormOpen = false;
        $this->editingMemberId = null;
    }

    public function saveMember(): void
    {
        try {
            if (! $this->isOwnedByThisTenant()) {
                throw new \RuntimeException('This corporate is managed centrally.');
            }
            $name = trim($this->memberName);
            $email = trim($this->memberEmail);
            if ($name === '') {
                throw new \RuntimeException('Name is required.');
            }
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('A valid email is required.');
            }
            if (! in_array($this->memberRole, ['member', 'admin'], true)) {
                throw new \RuntimeException('Pick a valid role.');
            }

            $data = [
                'name' => $name,
                'email' => $email,
                'phone' => trim($this->memberPhone) !== '' ? trim($this->memberPhone) : null,
                'employee_id' => trim($this->memberEmployeeId) !== '' ? trim($this->memberEmployeeId) : null,
                'role' => $this->memberRole,
            ];

            if ($this->editingMemberId !== null) {
                $member = CorporateMember::query()
                    ->where('corporate_id', $this->corporateId)
                    ->findOrFail($this->editingMemberId);
                $member->forceFill($data)->save();
            } else {
                // New members land Active — the Manager profiling them
                // is the trust signal (matches how corporate check-in
                // in the Reservations flow reads member.status).
                $data['corporate_id'] = $this->corporateId;
                $data['status'] = \App\Enums\CorporateMemberStatus::Active->value;
                $data['invited_at'] = \Illuminate\Support\Carbon::now();
                $data['accepted_at'] = \Illuminate\Support\Carbon::now();
                CorporateMember::query()->create($data);
            }

            $this->closeMemberForm();
            $this->dispatch('notify',
                title: 'Saved',
                message: $this->editingMemberId ? 'Member updated.' : 'Member profiled.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t save', message: $e->getMessage(), kind: 'err');
        }
    }

    /**
     * Flip a member between Active and Offboarded so the Reservations
     * corporate direct check-in gate reflects the change immediately.
     */
    public function toggleMemberStatus(int $id): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        $member = CorporateMember::query()
            ->where('corporate_id', $this->corporateId)
            ->find($id);
        if ($member === null) {
            return;
        }
        $next = $member->status === \App\Enums\CorporateMemberStatus::Active
            ? \App\Enums\CorporateMemberStatus::Offboarded
            : \App\Enums\CorporateMemberStatus::Active;
        $update = ['status' => $next->value];
        if ($next === \App\Enums\CorporateMemberStatus::Offboarded) {
            $update['offboarded_at'] = \Illuminate\Support\Carbon::now();
        } else {
            $update['offboarded_at'] = null;
        }
        $member->forceFill($update)->save();
        $this->dispatch('notify',
            title: $next === \App\Enums\CorporateMemberStatus::Active ? 'Reactivated' : 'Offboarded',
            message: $member->name.' is now '.$next->value.'.',
            kind: 'ok',
        );
    }

    // ── Category rate actions ─────────────────────────────────────

    public function newCategoryRate(): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        $this->editingCategoryRateId = null;
        $this->categoryRateCategoryId = null;
        $this->categoryRateType = 'percent_off';
        $this->categoryRateValue = null;
        $this->categoryRateMinNights = null;
        $this->categoryRateNotes = '';
        $this->categoryRateFormOpen = true;
    }

    public function editCategoryRate(int $id): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        $rate = \App\Models\CorporateRate::query()
            ->where('corporate_id', $this->corporateId)
            ->where('scope', \App\Enums\CorporateRateScope::RoomCategory)
            ->find($id);
        if ($rate === null) {
            return;
        }
        $this->editingCategoryRateId = $rate->id;
        $this->categoryRateCategoryId = $rate->scope_id;
        $this->categoryRateType = $rate->rate_type->value;
        $this->categoryRateValue = $rate->rate_value;
        $this->categoryRateMinNights = $rate->min_nights;
        $this->categoryRateNotes = (string) $rate->notes;
        $this->categoryRateFormOpen = true;
    }

    public function closeCategoryRateForm(): void
    {
        $this->categoryRateFormOpen = false;
        $this->editingCategoryRateId = null;
    }

    public function saveCategoryRate(): void
    {
        try {
            if (! $this->isOwnedByThisTenant()) {
                throw new \RuntimeException('This corporate is managed centrally.');
            }
            if ($this->categoryRateCategoryId === null) {
                throw new \RuntimeException('Pick a room category first.');
            }
            $type = \App\Enums\CorporateRateType::tryFrom($this->categoryRateType);
            if ($type === null) {
                throw new \RuntimeException('Pick a valid rate type.');
            }
            if ($this->categoryRateValue === null || $this->categoryRateValue <= 0) {
                throw new \RuntimeException('Value must be above 0.');
            }
            if ($type === \App\Enums\CorporateRateType::PercentOff && $this->categoryRateValue > 100) {
                throw new \RuntimeException('Percent off cannot exceed 100.');
            }
            // Sanity: the picked category must belong to this tenant.
            $category = \App\Models\RoomCategory::query()->find($this->categoryRateCategoryId);
            if ($category === null) {
                throw new \RuntimeException('Category not found.');
            }
            $minNights = $this->categoryRateMinNights !== null && $this->categoryRateMinNights > 0
                ? $this->categoryRateMinNights
                : null;

            $data = [
                'corporate_id' => $this->corporateId,
                'scope' => \App\Enums\CorporateRateScope::RoomCategory->value,
                'scope_id' => $this->categoryRateCategoryId,
                'rate_type' => $type->value,
                'rate_value' => (int) $this->categoryRateValue,
                'min_nights' => $minNights,
                'notes' => trim($this->categoryRateNotes) !== '' ? trim($this->categoryRateNotes) : null,
            ];

            if ($this->editingCategoryRateId !== null) {
                $rate = \App\Models\CorporateRate::query()
                    ->where('corporate_id', $this->corporateId)
                    ->findOrFail($this->editingCategoryRateId);
                $rate->forceFill($data)->save();
            } else {
                \App\Models\CorporateRate::query()->create($data);
            }
            $this->closeCategoryRateForm();
            $this->dispatch('notify',
                title: 'Saved',
                message: 'Category rate updated.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t save', message: $e->getMessage(), kind: 'err');
        }
    }

    public function deleteCategoryRate(int $id): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        \App\Models\CorporateRate::query()
            ->where('corporate_id', $this->corporateId)
            ->where('scope', \App\Enums\CorporateRateScope::RoomCategory)
            ->where('id', $id)
            ->delete();
        $this->dispatch('notify',
            title: 'Removed',
            message: 'Category rate deleted.',
            kind: 'ok',
        );
    }

    // ── Length-of-stay discount actions ───────────────────────────
    // Modelled as Global-scoped CorporateRate rows with rate_type =
    // PercentOff and min_nights set. Rate resolution already respects
    // scope precedence + min_nights, so LoS discounts stack under
    // category rates naturally.

    public function newLos(): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        $this->editingLosId = null;
        $this->losMinNights = null;
        $this->losPercentOff = null;
        $this->losNotes = '';
        $this->losFormOpen = true;
    }

    public function editLos(int $id): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        $rate = \App\Models\CorporateRate::query()
            ->where('corporate_id', $this->corporateId)
            ->where('scope', \App\Enums\CorporateRateScope::Global)
            ->find($id);
        if ($rate === null) {
            return;
        }
        $this->editingLosId = $rate->id;
        $this->losMinNights = $rate->min_nights;
        $this->losPercentOff = $rate->rate_value;
        $this->losNotes = (string) $rate->notes;
        $this->losFormOpen = true;
    }

    public function closeLosForm(): void
    {
        $this->losFormOpen = false;
        $this->editingLosId = null;
    }

    public function saveLos(): void
    {
        try {
            if (! $this->isOwnedByThisTenant()) {
                throw new \RuntimeException('This corporate is managed centrally.');
            }
            if ($this->losMinNights === null || $this->losMinNights < 2) {
                throw new \RuntimeException('Minimum nights must be 2 or more.');
            }
            if ($this->losPercentOff === null || $this->losPercentOff <= 0 || $this->losPercentOff > 100) {
                throw new \RuntimeException('Discount must be between 1 and 100 percent.');
            }

            $data = [
                'corporate_id' => $this->corporateId,
                'scope' => \App\Enums\CorporateRateScope::Global->value,
                'scope_id' => null,
                'rate_type' => \App\Enums\CorporateRateType::PercentOff->value,
                'rate_value' => (int) $this->losPercentOff,
                'min_nights' => (int) $this->losMinNights,
                'notes' => trim($this->losNotes) !== '' ? trim($this->losNotes) : null,
            ];

            if ($this->editingLosId !== null) {
                $rate = \App\Models\CorporateRate::query()
                    ->where('corporate_id', $this->corporateId)
                    ->findOrFail($this->editingLosId);
                $rate->forceFill($data)->save();
            } else {
                \App\Models\CorporateRate::query()->create($data);
            }
            $this->closeLosForm();
            $this->dispatch('notify',
                title: 'Saved',
                message: 'Length-of-stay discount updated.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t save', message: $e->getMessage(), kind: 'err');
        }
    }

    public function deleteLos(int $id): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        \App\Models\CorporateRate::query()
            ->where('corporate_id', $this->corporateId)
            ->where('scope', \App\Enums\CorporateRateScope::Global)
            ->where('id', $id)
            ->delete();
        $this->dispatch('notify',
            title: 'Removed',
            message: 'Length-of-stay discount deleted.',
            kind: 'ok',
        );
    }

    // ── accepts_reservations toggle ───────────────────────────────

    public function toggleAcceptsReservations(): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        $corp = Corporate::query()->find($this->corporateId);
        if ($corp === null) {
            return;
        }
        $corp->accepts_reservations = ! $corp->accepts_reservations;
        $corp->save();
        $this->dispatch('notify',
            title: $corp->accepts_reservations ? 'Now accepting' : 'Paused',
            message: $corp->accepts_reservations
                ? 'Reservations for this corp are open again.'
                : 'New reservations from this corp will be refused.',
            kind: 'ok',
        );
    }

    // ── Corporate reservation actions ─────────────────────────────

    public function newReservation(): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        $this->editingReservationId = null;
        $this->reservationPropertyId = null;
        $this->reservationStartsAt = \Illuminate\Support\Carbon::today()->toDateString();
        $this->reservationEndsAt = \Illuminate\Support\Carbon::today()->addDays(2)->toDateString();
        $this->reservationCategoryRows = [
            ['room_category_id' => null, 'rooms_count' => 1],
        ];
        $this->reservationMemberIds = [];
        $this->reservationNotes = '';
        $this->reservationFormOpen = true;
    }

    /**
     * Rehydrate the reservation form from an existing row. Refuses to
     * open a Cancelled or Closed reservation for edit — those are
     * terminal states and the audit trail matters (an operator can
     * always create a new one instead).
     */
    public function editReservation(int $id): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        $r = \App\Models\CorporateReservation::query()
            ->with(['categories', 'members'])
            ->where('corporate_id', $this->corporateId)
            ->find($id);
        if ($r === null) {
            return;
        }
        if (in_array($r->status, [
            \App\Enums\CorporateReservationStatus::Cancelled,
            \App\Enums\CorporateReservationStatus::Closed,
        ], true)) {
            $this->dispatch('notify',
                title: 'Can\'t edit',
                message: 'This reservation is '.$r->status->value.'. Create a new one instead.',
                kind: 'err',
            );
            return;
        }
        $this->editingReservationId = $r->id;
        $this->reservationPropertyId = $r->property_id;
        $this->reservationStartsAt = $r->starts_at?->toDateString() ?? '';
        $this->reservationEndsAt = $r->ends_at?->toDateString() ?? '';
        $this->reservationCategoryRows = $r->categories->map(fn ($c) => [
            'room_category_id' => (int) $c->room_category_id,
            'rooms_count'      => (int) $c->rooms_count,
        ])->all();
        if ($this->reservationCategoryRows === []) {
            $this->reservationCategoryRows = [['room_category_id' => null, 'rooms_count' => 1]];
        }
        $this->reservationMemberIds = $r->members->pluck('corporate_member_id')->map('intval')->all();
        $this->reservationNotes = (string) $r->notes;
        $this->reservationFormOpen = true;
    }

    public function addReservationCategoryRow(): void
    {
        $this->reservationCategoryRows[] = ['room_category_id' => null, 'rooms_count' => 1];
    }

    public function removeReservationCategoryRow(int $idx): void
    {
        unset($this->reservationCategoryRows[$idx]);
        $this->reservationCategoryRows = array_values($this->reservationCategoryRows);
    }

    public function toggleReservationMember(int $memberId): void
    {
        $key = array_search($memberId, $this->reservationMemberIds, true);
        if ($key === false) {
            $this->reservationMemberIds[] = $memberId;
        } else {
            unset($this->reservationMemberIds[$key]);
            $this->reservationMemberIds = array_values($this->reservationMemberIds);
        }
    }

    public function closeReservationForm(): void
    {
        $this->reservationFormOpen = false;
        $this->editingReservationId = null;
    }

    public function saveReservation(): void
    {
        try {
            if (! $this->isOwnedByThisTenant()) {
                throw new \RuntimeException('This corporate is managed centrally.');
            }
            $corp = Corporate::query()->findOrFail($this->corporateId);

            // Acceptance is a NEW-reservation gate. Editing an
            // existing (Pending / Active) reservation should still
            // work while the corp is paused — the manager might be
            // fixing a mistake before shutting the door on new ones.
            $isEdit = $this->editingReservationId !== null;
            if (! $isEdit && ! (bool) $corp->accepts_reservations) {
                throw new \RuntimeException('Reservations are paused for this corp. Toggle acceptance back on first.');
            }
            if ($this->reservationPropertyId === null) {
                throw new \RuntimeException('Pick a property (branch) first.');
            }
            if ($this->reservationStartsAt === '' || $this->reservationEndsAt === '') {
                throw new \RuntimeException('Pick both a start + end date.');
            }
            $start = \Illuminate\Support\Carbon::parse($this->reservationStartsAt)->startOfDay();
            $end = \Illuminate\Support\Carbon::parse($this->reservationEndsAt)->startOfDay();
            if ($end->lessThanOrEqualTo($start)) {
                throw new \RuntimeException('End date must be after the start date.');
            }
            $property = \App\Models\Property::query()->find($this->reservationPropertyId);
            if ($property === null) {
                throw new \RuntimeException('Property not found.');
            }

            // Normalise category rows: drop empty picks + zero counts,
            // guard against duplicate categories (would violate the
            // unique index anyway; friendlier here).
            $rows = collect($this->reservationCategoryRows)
                ->filter(fn ($r) => ! empty($r['room_category_id']) && (int) ($r['rooms_count'] ?? 0) > 0)
                ->values()
                ->all();
            if ($rows === []) {
                throw new \RuntimeException('Add at least one category with a room count.');
            }
            $seenCats = [];
            foreach ($rows as $r) {
                $cid = (int) $r['room_category_id'];
                if (isset($seenCats[$cid])) {
                    throw new \RuntimeException('You picked the same category twice — merge the counts.');
                }
                $seenCats[$cid] = true;
            }

            $operator = auth('web')->user();

            \Illuminate\Support\Facades\DB::transaction(function () use ($corp, $property, $start, $end, $rows, $operator, $isEdit): void {
                if ($isEdit) {
                    $reservation = \App\Models\CorporateReservation::query()
                        ->where('corporate_id', $corp->id)
                        ->findOrFail($this->editingReservationId);
                    if (in_array($reservation->status, [
                        \App\Enums\CorporateReservationStatus::Cancelled,
                        \App\Enums\CorporateReservationStatus::Closed,
                    ], true)) {
                        throw new \RuntimeException('Cannot edit a Cancelled or Closed reservation.');
                    }
                    $reservation->forceFill([
                        'property_id' => $property->id,
                        'starts_at'   => $start,
                        'ends_at'     => $end,
                        'notes'       => trim($this->reservationNotes) !== '' ? trim($this->reservationNotes) : null,
                    ])->save();
                } else {
                    $reservation = \App\Models\CorporateReservation::query()->create([
                        'corporate_id' => $corp->id,
                        'property_id'  => $property->id,
                        'starts_at'    => $start,
                        'ends_at'      => $end,
                        'status'       => \App\Enums\CorporateReservationStatus::Pending->value,
                        'notes'        => trim($this->reservationNotes) !== '' ? trim($this->reservationNotes) : null,
                        'created_by_operator_id' => $operator?->getKey(),
                    ]);
                }

                // Category + member rows: rewrite-in-place (delete +
                // re-insert). Preserves audit at the reservation
                // level and sidesteps the reconciliation complexity —
                // volumes are tiny (≤ dozen rows per reservation).
                // assigned_booking_id lookups are on the members-side
                // reference, so wiping only clears rows that haven't
                // been checked in yet (see the guard below).
                if ($isEdit) {
                    // Preserve any member rows that already have an
                    // assigned booking — clearing those would orphan
                    // the reservation → booking pointer.
                    $preservedMemberIds = \App\Models\CorporateReservationMember::query()
                        ->where('corporate_reservation_id', $reservation->id)
                        ->whereNotNull('assigned_booking_id')
                        ->pluck('corporate_member_id')
                        ->all();
                    \App\Models\CorporateReservationCategory::query()
                        ->where('corporate_reservation_id', $reservation->id)
                        ->delete();
                    \App\Models\CorporateReservationMember::query()
                        ->where('corporate_reservation_id', $reservation->id)
                        ->whereNull('assigned_booking_id')
                        ->delete();
                    // Merge preserved members with the newly-selected
                    // set so the operator sees the complete list.
                    foreach ($preservedMemberIds as $pid) {
                        if (! in_array((int) $pid, $this->reservationMemberIds, true)) {
                            $this->reservationMemberIds[] = (int) $pid;
                        }
                    }
                }

                foreach ($rows as $r) {
                    \App\Models\CorporateReservationCategory::query()->create([
                        'corporate_reservation_id' => $reservation->id,
                        'room_category_id' => (int) $r['room_category_id'],
                        'rooms_count' => (int) $r['rooms_count'],
                    ]);
                }
                foreach ($this->reservationMemberIds as $mid) {
                    \App\Models\CorporateReservationMember::query()->firstOrCreate([
                        'corporate_reservation_id' => $reservation->id,
                        'corporate_member_id' => (int) $mid,
                    ]);
                }
            });

            $wasEdit = $isEdit;
            $this->closeReservationForm();
            $this->dispatch('notify',
                title: $wasEdit ? 'Reservation updated' : 'Reservation created',
                message: $wasEdit
                    ? 'Changes saved. Assigned bookings were kept intact.'
                    : 'Front desk will see the expected staff on arrival.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t save', message: $e->getMessage(), kind: 'err');
        }
    }

    public function cancelReservation(int $id): void
    {
        if (! $this->isOwnedByThisTenant()) {
            return;
        }
        $r = \App\Models\CorporateReservation::query()
            ->where('corporate_id', $this->corporateId)
            ->find($id);
        if ($r === null) {
            return;
        }
        $r->forceFill(['status' => \App\Enums\CorporateReservationStatus::Cancelled->value])->save();
        $this->dispatch('notify',
            title: 'Cancelled',
            message: 'Reservation window cancelled. Expected members can no longer be assigned.',
            kind: 'ok',
        );
    }

    // ── Consolidated corporate invoice ────────────────────────────

    public function openConsolidatedForm(): void
    {
        // Default the window to the current month — the usual "bill
        // the corp for what happened this month" ask. Operator can
        // shrink or widen from there.
        $this->consolidatedFrom = \Illuminate\Support\Carbon::now()->startOfMonth()->toDateString();
        $this->consolidatedTo = \Illuminate\Support\Carbon::now()->endOfMonth()->toDateString();
        $this->consolidatedNotes = '';
        $this->consolidatedFormOpen = true;
    }

    public function closeConsolidatedForm(): void
    {
        $this->consolidatedFormOpen = false;
    }

    public function issueConsolidatedInvoice(
        \App\Actions\Invoicing\CreateConsolidatedCorporateInvoice $create,
    ) {
        try {
            if ($this->consolidatedFrom === '' || $this->consolidatedTo === '') {
                throw new \RuntimeException('Pick both dates for the invoice window.');
            }
            $from = \Illuminate\Support\Carbon::parse($this->consolidatedFrom)->startOfDay();
            $to = \Illuminate\Support\Carbon::parse($this->consolidatedTo)->endOfDay();
            if ($to->lessThan($from)) {
                throw new \RuntimeException('End date must be after the start date.');
            }

            $corp = Corporate::query()->findOrFail($this->corporateId);
            $tenant = Tenant::current();
            $operator = auth('web')->user();
            if ($tenant === null || ! $operator instanceof \App\Models\Operator) {
                throw new \RuntimeException('You must be signed in on a tenant.');
            }

            $invoice = $create->handle(
                tenant: $tenant,
                corporate: $corp,
                operator: $operator,
                dateFrom: $from,
                dateTo: $to,
                notes: trim($this->consolidatedNotes) !== '' ? trim($this->consolidatedNotes) : null,
                dueAt: null,
                issue: true,
            );

            $this->closeConsolidatedForm();
            return $this->redirect(
                route('tenant.invoices.show', ['invoice' => $invoice->id]),
                navigate: true,
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t issue', message: $e->getMessage(), kind: 'err');
            return null;
        }
    }

    // ── Corporate ledger: record a payment ────────────────────────

    public function openPaymentForm(): void
    {
        // Prefill with the current AR outstanding so the operator can
        // hit Record payment on a "paid in full" transfer without
        // re-typing. Same formula the Ledger + stats card use: sum
        // of unpaid Issued invoice balances. Prefill only when there's
        // something to owe — a zero prefill would read as coercive.
        $owed = (int) \App\Models\Invoice::query()
            ->where(function ($q): void {
                $q->where('corporate_id', $this->corporateId)
                    ->orWhereHas('booking', fn ($b) => $b->where('corporate_id', $this->corporateId));
            })
            ->whereIn('status', [\App\Enums\InvoiceStatus::Issued, \App\Enums\InvoiceStatus::Partial])
            ->get()
            ->sum(fn ($inv) => max(0, (int) $inv->total - (int) $inv->amount_paid));

        $this->paymentAmount = $owed > 0 ? $owed : null;
        $this->paymentMethod = 'transfer';
        $this->paymentReference = '';
        $this->paymentPaidAt = \Illuminate\Support\Carbon::now()->toDateString();
        $this->paymentNote = '';
        $this->paymentReceipt = null;
        $this->paymentTargetInvoiceId = null;
        $this->paymentFormOpen = true;
    }

    /**
     * Ledger row → "Pay" button. Opens the same Record payment drawer
     * pre-filled with THIS invoice's outstanding balance and stashes
     * the invoice id so RecordCorporatePayment settles this row first
     * before falling through to FIFO on any leftover.
     */
    public function openPaymentForInvoice(int $invoiceId): void
    {
        $invoice = \App\Models\Invoice::query()
            ->where(function ($q): void {
                $q->where('corporate_id', $this->corporateId)
                    ->orWhereHas('booking', fn ($b) => $b->where('corporate_id', $this->corporateId));
            })
            ->whereIn('status', [\App\Enums\InvoiceStatus::Issued, \App\Enums\InvoiceStatus::Partial])
            ->find($invoiceId);
        if ($invoice === null) {
            $this->dispatch('notify',
                title: 'Not found',
                message: 'That invoice is no longer open on this corp.',
                kind: 'err');
            return;
        }
        $bal = max(0, (int) $invoice->total - (int) $invoice->amount_paid);

        $this->paymentAmount = $bal > 0 ? $bal : null;
        $this->paymentMethod = 'transfer';
        $this->paymentReference = '';
        $this->paymentPaidAt = \Illuminate\Support\Carbon::now()->toDateString();
        $this->paymentNote = '';
        $this->paymentReceipt = null;
        $this->paymentTargetInvoiceId = $invoiceId;
        $this->paymentFormOpen = true;
    }

    public function closePaymentForm(): void
    {
        $this->paymentFormOpen = false;
        $this->paymentReceipt = null;
        $this->paymentTargetInvoiceId = null;
    }

    /**
     * Record incoming money from the corp. The RecordCorporatePayment
     * action walks unpaid invoices oldest-first and applies the total
     * across them, updating each invoice's amount_paid + status.
     */
    public function recordPayment(\App\Actions\Invoicing\RecordCorporatePayment $record): void
    {
        try {
            $corp = Corporate::query()->findOrFail($this->corporateId);
            $operator = auth('web')->user();
            if (! $operator instanceof \App\Models\Operator) {
                throw new \RuntimeException('You must be signed in.');
            }
            if ($this->paymentAmount === null || $this->paymentAmount <= 0) {
                throw new \RuntimeException('Enter an amount above ₦0.');
            }

            $receiptPath = null;
            if ($this->paymentReceipt !== null) {
                // Under the business's own folder, like guest IDs.
                $receiptPath = $this->paymentReceipt->store(
                    'receipts/corporate-payments/'.$corp->tenant_id.'/'.$corp->id,
                    'public',
                );
            }

            $paidAt = trim($this->paymentPaidAt) !== ''
                ? \Illuminate\Support\Carbon::parse($this->paymentPaidAt)
                : \Illuminate\Support\Carbon::now();

            $targetId = $this->paymentTargetInvoiceId;
            $record->handle(
                corporate: $corp,
                operator: $operator,
                amount: (int) $this->paymentAmount,
                method: $this->paymentMethod,
                paidAt: $paidAt,
                reference: trim($this->paymentReference) !== '' ? trim($this->paymentReference) : null,
                note: trim($this->paymentNote) !== '' ? trim($this->paymentNote) : null,
                receiptPath: $receiptPath,
                targetInvoiceId: $targetId,
            );

            $this->closePaymentForm();
            $this->dispatch('notify',
                title: 'Payment recorded',
                message: $targetId !== null
                    ? 'Settled the picked invoice first, overflow (if any) went to the next oldest.'
                    : 'Applied to the oldest unpaid invoices first.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t record', message: $e->getMessage(), kind: 'err');
        }
    }

    // ── Wallet: top-up ────────────────────────────────────────────
    public function openTopupForm(): void
    {
        $this->topupAmount = null;
        $this->topupSource = 'paystack';
        $this->topupMethod = 'transfer';
        $this->topupReference = '';
        $this->topupNote = '';
        $this->topupFormOpen = true;
    }

    public function closeTopupForm(): void
    {
        $this->topupFormOpen = false;
    }

    /**
     * Record a manual (cash / bank transfer) top-up. Wallet credits
     * immediately via WalletService::topup(). Paystack path is separate
     * and would call TopupService::start() to hand back a hosted URL —
     * left as a TODO on the Paystack branch since it needs the corporate
     * portal callback flow to fire.
     */
    public function recordTopup(\App\Services\Corporates\WalletService $wallets): void
    {
        try {
            $corp = Corporate::query()->findOrFail($this->corporateId);
            $operator = auth('web')->user();
            if (! $operator instanceof \App\Models\Operator) {
                throw new \RuntimeException('You must be signed in.');
            }
            if ($this->topupAmount === null || $this->topupAmount <= 0) {
                throw new \RuntimeException('Enter an amount above ₦0.');
            }

            if ($this->topupSource === 'paystack') {
                // Paystack path: hand off to TopupService, then flash
                // the authorization URL back to the operator so they
                // can share it with the corp finance contact. Wallet
                // credits when the webhook fires — no immediate
                // balance change here.
                $result = app(\App\Services\Corporates\TopupService::class)->start(
                    corporate: $corp,
                    amountNaira: (int) $this->topupAmount,
                    initiator: $operator,
                    note: trim($this->topupNote) !== '' ? trim($this->topupNote) : null,
                );
                $this->closeTopupForm();
                $this->dispatch('notify',
                    title: 'Top-up link ready',
                    message: 'Share this URL with the corp: '.$result['authorization_url'],
                    kind: 'ok',
                );
                return;
            }

            // Manual path: operator confirms receipt of cash / transfer
            // off-platform. Credit the wallet directly + tag with the
            // reference / note so the transactions view can trace it.
            $wallets->topup(
                corporate: $corp,
                amount: (int) $this->topupAmount,
                description: 'Manual '.$this->topupMethod.' top-up'
                    .(trim($this->topupReference) !== '' ? ' · ref '.trim($this->topupReference) : ''),
                paystackReference: null,
                causer: $operator,
            );

            $this->closeTopupForm();
            $this->dispatch('notify',
                title: 'Wallet topped up',
                message: '₦'.number_format((int) $this->topupAmount).' credited to the wallet.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t top up', message: $e->getMessage(), kind: 'err');
        }
    }

    /**
     * Ledger convenience: prefill the payment form with "wallet" as
     * the source + the min(wallet available, ledger outstanding) as
     * the amount. Handy shortcut for the operator's common flow.
     */
    public function payFromWallet(\App\Services\Corporates\WalletService $wallets): void
    {
        $corp = Corporate::query()->find($this->corporateId);
        if ($corp === null) return;

        $wallet = $wallets->ensureWallet($corp);
        $available = max(0, (int) $wallet->balance_naira - (int) $wallet->held_naira);
        if ($available <= 0) {
            $this->dispatch('notify',
                title: 'Wallet empty',
                message: 'Top up before paying from wallet.',
                kind: 'err');
            return;
        }

        $owed = (int) \App\Models\Invoice::query()
            ->where(function ($q): void {
                $q->where('corporate_id', $this->corporateId)
                    ->orWhereHas('booking', fn ($b) => $b->where('corporate_id', $this->corporateId));
            })
            ->whereIn('status', [\App\Enums\InvoiceStatus::Issued, \App\Enums\InvoiceStatus::Partial])
            ->get()
            ->sum(fn ($inv) => max(0, (int) $inv->total - (int) $inv->amount_paid));

        $this->paymentAmount = min($available, max(1, $owed));
        $this->paymentMethod = 'wallet';
        $this->paymentReference = '';
        $this->paymentPaidAt = \Illuminate\Support\Carbon::now()->toDateString();
        $this->paymentNote = 'Debited from wallet balance';
        $this->paymentReceipt = null;
        $this->paymentTargetInvoiceId = null;
        $this->paymentFormOpen = true;
    }

    private function isOwnedByThisTenant(): bool
    {
        $tenantId = (int) (Tenant::current()?->id ?? 0);
        $corp = Corporate::query()->find($this->corporateId);
        return $corp !== null && (int) $corp->created_by_tenant_id === $tenantId;
    }

    public function render(): View
    {
        $corporate = Corporate::query()->findOrFail($this->corporateId);
        $tenantId  = (int) (Tenant::current()?->id ?? 0);
        $isOwned = (int) $corporate->created_by_tenant_id === $tenantId;

        $rows = $this->buildRows($corporate);
        $memberIds = $rows->pluck('member_id')->filter()->unique()->all();

        // On a this-tenant-owned corp, show ALL profiled members even
        // if they haven't done business here yet. On centrally-owned
        // corps, keep the old behaviour (only members with activity).
        $members = $isOwned
            ? CorporateMember::query()
                ->where('corporate_id', $this->corporateId)
                ->orderBy('name')
                ->get()
            : ($memberIds === []
                ? new Collection
                : CorporateMember::query()
                    ->whereIn('id', $memberIds)
                    ->orderBy('name')
                    ->get());

        // Rate config — only surfaced on owned corps. Category rates
        // eager-load their RoomCategory (which carries the property_id
        // so the table can print the branch name for multi-property
        // tenants). LoS discounts are corporate-wide, no join.
        $categoryRates = collect();
        $losDiscounts = collect();
        $categoryOptions = collect();
        if ($isOwned) {
            $rates = \App\Models\CorporateRate::query()
                ->where('corporate_id', $this->corporateId)
                ->orderBy('id')
                ->get();
            $categoryRates = $rates->where('scope', \App\Enums\CorporateRateScope::RoomCategory)->values();
            $losDiscounts = $rates->where('scope', \App\Enums\CorporateRateScope::Global)->values();

            // Hydrate the RoomCategory so the table can show name +
            // branch. Cross-tenant categories can't leak here because
            // RoomCategory has the BelongsToTenant scope.
            $catIds = $categoryRates->pluck('scope_id')->filter()->unique()->all();
            $categoriesById = $catIds === []
                ? collect()
                : \App\Models\RoomCategory::query()
                    ->with('property:id,name')
                    ->whereIn('id', $catIds)
                    ->get()
                    ->keyBy('id');
            $categoryRates = $categoryRates->map(function ($rate) use ($categoriesById) {
                $rate->setRelation('room_category_resolved', $categoriesById->get((int) $rate->scope_id));
                return $rate;
            });

            // Selector options: every room category in this tenant, so
            // the Manager can attach a rate to any branch's category.
            $categoryOptions = \App\Models\RoomCategory::query()
                ->with('property:id,name')
                ->orderBy('property_id')
                ->orderBy('name')
                ->get();
        }

        // Corporate reservations panel data — owned corps only.
        $reservations = collect();
        $properties = collect();
        $categoriesByProperty = collect();
        $activeMembers = collect();
        if ($isOwned) {
            $reservations = \App\Models\CorporateReservation::query()
                ->with(['property:id,name', 'categories.roomCategory', 'members.member'])
                ->where('corporate_id', $this->corporateId)
                ->orderByDesc('starts_at')
                ->get();

            $properties = \App\Models\Property::query()
                ->orderBy('name')
                ->get();

            // Only categories under the picked property show up in the
            // row selector — RoomCategory is property-scoped. Grouped
            // by property_id so the blade can filter client-side once
            // the operator picks a property.
            $categoriesByProperty = \App\Models\RoomCategory::query()
                ->orderBy('name')
                ->get()
                ->groupBy('property_id');

            $activeMembers = \App\Models\CorporateMember::query()
                ->where('corporate_id', $this->corporateId)
                ->where('status', \App\Enums\CorporateMemberStatus::Active->value)
                ->orderBy('name')
                ->get();
        }

        // Ledger — every invoice linked to this corp. Preferred path
        // is the denormalised invoices.corporate_id column. Falls back
        // to booking.corporate_id via a subquery so invoices created
        // before we started stamping the column (or where the caller
        // passed a $booking whose corporate_id hadn't been set yet)
        // still surface on the corp's page.
        $ledgerInvoices = \App\Models\Invoice::query()
            ->with(['booking:id,booking_ref,guest_name,checkin_date,checkout_date,corporate_id'])
            ->where(function ($q): void {
                $q->where('corporate_id', $this->corporateId)
                    ->orWhereHas('booking', fn ($b) => $b->where('corporate_id', $this->corporateId));
            })
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get();
        $ledgerOutstanding = (int) $ledgerInvoices
            ->whereIn('status', [\App\Enums\InvoiceStatus::Issued, \App\Enums\InvoiceStatus::Partial])
            ->sum(fn ($inv) => max(0, (int) $inv->total - (int) $inv->amount_paid));
        $ledgerPayments = \App\Models\CorporatePayment::query()
            ->with(['recordedBy:id,name', 'allocations.invoice:id,number'])
            ->where('corporate_id', $this->corporateId)
            ->orderByDesc('paid_at')
            ->limit(20)
            ->get();

        // Statement — one time-ordered feed of every invoice issued
        // against the corp + every payment received, with a running
        // balance so operators can eyeball "what was owed before/after
        // this event". Debits = invoices (Issued/Paid; Draft + Void
        // are excluded — a Draft isn't a live receivable, a Void is
        // reversed). Credits = payments.
        $statementEvents = collect();
        foreach ($ledgerInvoices as $inv) {
            if ($inv->status === \App\Enums\InvoiceStatus::Draft
                || $inv->status === \App\Enums\InvoiceStatus::Void) {
                continue;
            }
            $when = $inv->issued_at ?? $inv->created_at;
            $statementEvents->push((object) [
                'kind'      => 'invoice',
                'when'      => $when,
                'reference' => $inv->number ?? 'Draft #'.$inv->id,
                'note'      => $inv->booking?->guest_name
                    ? $inv->booking->guest_name.' · '.$inv->booking->booking_ref
                    : 'Consolidated',
                'debit'     => (int) $inv->total,
                'credit'    => 0,
                'record'    => $inv,
                'invoice_id' => $inv->id,
            ]);
        }
        foreach ($ledgerPayments as $pay) {
            $statementEvents->push((object) [
                'kind'      => 'payment',
                'when'      => $pay->paid_at ?? $pay->created_at,
                'reference' => $pay->reference ?: 'Payment · '.ucfirst($pay->method),
                'note'      => $pay->note
                    ?: ($pay->allocations->isNotEmpty()
                        ? 'Applied to '.$pay->allocations
                            ->map(fn ($a) => $a->invoice?->number ?? 'Draft')
                            ->implode(', ')
                        : 'Unallocated credit'),
                'debit'     => 0,
                'credit'    => (int) $pay->amount_naira,
                'record'    => $pay,
                'invoice_id' => null,
            ]);
        }
        // Chronological (oldest first) so the running balance climbs
        // through time. Stable secondary sort on kind so an invoice
        // issued the same instant as a payment shows first (charge
        // before credit is the accounting convention).
        $statementEvents = $statementEvents
            ->sortBy([
                fn ($a, $b) => $a->when <=> $b->when,
                fn ($a, $b) => ($a->kind === 'invoice' ? 0 : 1) <=> ($b->kind === 'invoice' ? 0 : 1),
            ])
            ->values();
        $running = 0;
        foreach ($statementEvents as $ev) {
            $ev->balance_before = $running;
            $running += $ev->debit - $ev->credit;
            $ev->balance_after = $running;
        }
        // Display newest first, but keep balance_after already
        // computed against the chronological walk so the numbers
        // still line up as you read down.
        $statementEvents = $statementEvents->reverse()->values();

        // Bookings the corp still owes for but that aren't on any
        // Issued/Paid invoice yet. Includes CheckedIn / CheckedOut
        // corp bookings with balance_due > 0. Landing point for the
        // operator to click Generate invoice — otherwise they'd finish
        // a stay + the outstanding disappears from view and the corp
        // never gets billed.
        //
        // "Already invoiced" covers two shapes:
        //   1. A per-booking invoice: invoice.booking_id = this booking.
        //   2. A consolidated corp invoice: invoice.booking_id is NULL
        //      but the InvoiceLine source_type/source_id points at
        //      this booking. Missing (2) meant a stay that was already
        //      bundled into a consolidated invoice kept showing under
        //      "Ready to invoice" and would get double-billed.
        $uninvoicedStays = \App\Models\Booking::query()
            ->with(['room:id,room_number'])
            ->where('corporate_id', $this->corporateId)
            ->where('balance_due', '>', 0)
            ->whereIn('booking_status', [
                \App\Enums\BookingStatus::CheckedIn->value,
                \App\Enums\BookingStatus::CheckedOut->value,
            ])
            ->whereDoesntHave('invoices', function ($q): void {
                $q->whereIn('status', [
                    \App\Enums\InvoiceStatus::Issued->value,
                    \App\Enums\InvoiceStatus::Partial->value,
                    \App\Enums\InvoiceStatus::Paid->value,
                ]);
            })
            ->whereNotExists(function ($sub): void {
                $sub->from('invoice_lines')
                    ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
                    ->whereColumn('invoice_lines.source_id', 'bookings.id')
                    ->where('invoice_lines.source_type', \App\Models\Booking::class)
                    ->whereIn('invoices.status', [
                        \App\Enums\InvoiceStatus::Issued->value,
                        \App\Enums\InvoiceStatus::Partial->value,
                        \App\Enums\InvoiceStatus::Paid->value,
                    ]);
            })
            ->orderByDesc('checkout_date')
            ->limit(20)
            ->get();
        $uninvoicedOutstanding = (int) $uninvoicedStays->sum(fn ($b) => (int) $b->balance_due);

        // Wallet: ensure the CorporateWallet row exists so the UI has
        // a stable object to render off. `held` is money already
        // committed to open bookings; `available` = balance − held.
        $wallet = app(\App\Services\Corporates\WalletService::class)->ensureWallet($corporate);
        $walletBalance = (int) $wallet->balance_naira;
        $walletHeld = (int) $wallet->held_naira;
        $walletAvailable = max(0, $walletBalance - $walletHeld);

        // Recent wallet transactions (last 20). Renders on the wallet
        // panel as a compact ledger so operators can trace top-ups
        // and debits without leaving the page.
        $walletTx = \App\Models\CorporateWalletTransaction::query()
            ->where('corporate_id', $this->corporateId)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('livewire.dashboard.corporates.show', [
            'corporate'      => $corporate,
            'rows'           => $rows,
            'members'        => $members,
            'isOwned'        => $isOwned,
            'stats'          => [
                'gmv'           => (int) $rows->sum('amount'),
                'count'         => $rows->count(),
                'last_activity' => $rows->max('when'),
            ],
            'categoryRates'   => $categoryRates,
            'losDiscounts'    => $losDiscounts,
            'categoryOptions' => $categoryOptions,
            'reservations'    => $reservations,
            'properties'      => $properties,
            'categoriesByProperty' => $categoriesByProperty,
            'activeMembers'   => $activeMembers,
            'ledgerInvoices'  => $ledgerInvoices,
            'ledgerOutstanding' => $ledgerOutstanding,
            'ledgerPayments'  => $ledgerPayments,
            'statementEvents' => $statementEvents,
            'uninvoicedStays' => $uninvoicedStays,
            'uninvoicedOutstanding' => $uninvoicedOutstanding,
            'walletBalance'   => $walletBalance,
            'walletHeld'      => $walletHeld,
            'walletAvailable' => $walletAvailable,
            'walletTx'        => $walletTx,
        ]);
    }

    /**
     * Booking history rows across all four subject types, newest first.
     * Tenant-scoped via the BelongsToTenant traits.
     *
     * @return Collection<int, object>
     */
    private function buildRows(Corporate $corporate): Collection
    {
        $rows = new Collection;

        Booking::query()
            ->where('corporate_id', $corporate->id)
            ->with(['room.category', 'corporateMember'])
            ->latest('id')->limit(200)->get()
            ->each(fn ($b) => $rows->push((object) [
                'kind'      => 'Stay',
                'ref'       => $b->booking_ref,
                'when'      => $b->checkin_date,
                'subject'   => $b->room?->name ?? '-',
                'detail'    => $b->room?->category?->name,
                'member'    => $b->corporateMember?->name,
                'member_id' => $b->corporate_member_id,
                'amount'    => (int) $b->total_amount,
                'status'    => $b->booking_status->value,
                'route'     => route('tenant.bookings.show', $b->booking_ref),
            ]));

        DeskBooking::query()
            ->where('corporate_id', $corporate->id)
            ->with(['workspace', 'corporateMember'])
            ->latest('id')->limit(200)->get()
            ->each(fn ($d) => $rows->push((object) [
                'kind'      => 'Desk',
                'ref'       => $d->reference,
                'when'      => $d->start_at,
                'subject'   => $d->workspace?->name ?? '-',
                'detail'    => $d->workspace?->type?->label(),
                'member'    => $d->corporateMember?->name,
                'member_id' => $d->corporate_member_id,
                'amount'    => (int) $d->amount_naira,
                'status'    => $d->status->value,
                'route'     => null,
            ]));

        Membership::query()
            ->where('corporate_id', $corporate->id)
            ->with(['workspace', 'plan', 'corporateMember'])
            ->latest('id')->limit(200)->get()
            ->each(fn ($m) => $rows->push((object) [
                'kind'      => 'Membership',
                'ref'       => 'M-'.$m->id,
                'when'      => $m->started_at,
                'subject'   => $m->workspace?->name ?? '-',
                'detail'    => $m->plan?->name,
                'member'    => $m->corporateMember?->name,
                'member_id' => $m->corporate_member_id,
                'amount'    => (int) $m->amount_naira,
                'status'    => $m->status->value,
                'route'     => null,
            ]));

        Lease::query()
            ->where('corporate_id', $corporate->id)
            ->with(['unit', 'corporateMember'])
            ->latest('id')->limit(200)->get()
            ->each(fn ($l) => $rows->push((object) [
                'kind'      => 'Lease',
                'ref'       => $l->reference,
                'when'      => $l->started_at,
                'subject'   => $l->unit?->name ?? '-',
                'detail'    => $l->unit?->address,
                'member'    => $l->corporateMember?->name,
                'member_id' => $l->corporate_member_id,
                'amount'    => (int) $l->annual_rent_naira,
                'status'    => $l->status->value,
                'route'     => null,
            ]));

        return $rows->sortByDesc('when')->values();
    }
}

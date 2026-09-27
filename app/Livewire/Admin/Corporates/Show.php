<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Corporates;

use App\Enums\CorporateMemberRole;
use App\Enums\CorporateMemberStatus;
use App\Enums\CorporateRateScope;
use App\Enums\CorporateRateType;
use App\Models\Corporate;
use App\Models\CorporateDomain;
use App\Models\CorporateMember;
use App\Models\CorporateRate;
use App\Services\Corporates\WalletService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Single-corporate admin console with tabs:
 *
 *   overview (default) , summary + KYC + contract
 *   members            , roster CRUD with caps + roles
 *   wallet             , balance + top-up + adjust + transactions
 *   rates              , negotiated rate rules
 *   domains            , verified email-domain auto-link
 *
 * Tab state lives in the URL so deep links work.
 */
#[Layout('admin.layout')]
class Show extends Component
{
    #[Locked]
    public int $corporateId;

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    // Member form
    public string $member_name = '';
    public string $member_email = '';
    public string $member_phone = '';
    public string $member_employee_id = '';
    public string $member_role = 'member';
    public ?int $member_daily_cap = null;
    public ?int $member_monthly_cap = null;

    // Wallet actions
    public ?int $topup_amount = null;
    public string $topup_note = '';
    public string $topup_reference = '';
    public ?int $adjust_amount = null;
    public string $adjust_note = '';

    // Rate form
    public string $rate_scope = 'global';
    public ?int $rate_scope_id = null;
    public string $rate_type = 'percent_off';
    public ?int $rate_value = null;
    public ?string $rate_starts_at = null;
    public ?string $rate_ends_at = null;
    public string $rate_notes = '';

    // Domain form
    public string $domain_value = '';
    public bool $domain_verified = false;

    public function mount(Corporate $corporate): void
    {
        $this->corporateId = $corporate->id;
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'members', 'wallet', 'rates', 'domains'], true)) {
            $this->tab = $tab;
        }
    }

    // ── Members ────────────────────────────────────────────────────

    public function addMember(): void
    {
        $data = $this->validate([
            'member_name'        => ['required', 'string', 'max:160'],
            'member_email'       => ['required', 'email', 'max:180',
                Rule::unique('corporate_members', 'email')
                    ->where('corporate_id', $this->corporateId)
                    ->whereNull('deleted_at'),
            ],
            'member_phone'       => ['nullable', 'string', 'max:32'],
            'member_employee_id' => ['nullable', 'string', 'max:64'],
            'member_role'        => ['required', Rule::in(['admin', 'member'])],
            'member_daily_cap'   => ['nullable', 'integer', 'min:0'],
            'member_monthly_cap' => ['nullable', 'integer', 'min:0'],
        ]);

        // Admin role members get a one-time password so they can sign
        // into the self-service portal. Regular members don't (their
        // identity is verified at booking time by the email they type
        // into the public booking form).
        $generatedPassword = null;
        $passwordHash = null;
        if ($data['member_role'] === 'admin') {
            $generatedPassword = Str::password(16);
            $passwordHash = Hash::make($generatedPassword);
        }

        CorporateMember::query()->create([
            'corporate_id'       => $this->corporateId,
            'name'               => $data['member_name'],
            'email'              => $data['member_email'],
            'phone'              => $data['member_phone'] ?: null,
            'employee_id'        => $data['member_employee_id'] ?: null,
            'role'               => $data['member_role'],
            'status'             => CorporateMemberStatus::Active->value,
            'daily_cap_naira'    => $data['member_daily_cap'] ?? null,
            'monthly_cap_naira'  => $data['member_monthly_cap'] ?? null,
            'invited_at'         => now(),
            'accepted_at'        => now(),
            'password'           => $passwordHash,
        ]);

        $this->reset([
            'member_name', 'member_email', 'member_phone',
            'member_employee_id', 'member_daily_cap', 'member_monthly_cap',
        ]);
        $this->member_role = 'member';

        if ($generatedPassword !== null) {
            $this->dispatch('notify', title: 'Done', message: "Admin member added. One-time portal password: {$generatedPassword}", kind: 'ok');
        } else {
            $this->dispatch('notify', title: 'Done', message: 'Member added.', kind: 'ok');
        }
    }

    public function toggleMemberStatus(int $memberId): void
    {
        $member = CorporateMember::query()->find($memberId);
        if ($member === null || $member->corporate_id !== $this->corporateId) {
            return;
        }
        $member->status = $member->status === CorporateMemberStatus::Active
            ? CorporateMemberStatus::Suspended
            : CorporateMemberStatus::Active;
        $member->save();
        $this->dispatch('notify', title: 'Done', message: 'Member updated.', kind: 'ok');
    }

    public function removeMember(int $memberId): void
    {
        $member = CorporateMember::query()->find($memberId);
        if ($member === null || $member->corporate_id !== $this->corporateId) {
            return;
        }
        $member->update([
            'status'        => CorporateMemberStatus::Offboarded->value,
            'offboarded_at' => now(),
        ]);
        $member->delete();
        $this->dispatch('notify', title: 'Done', message: 'Member offboarded.', kind: 'ok');
    }

    // ── Wallet ─────────────────────────────────────────────────────

    public function topup(WalletService $wallets): void
    {
        $this->validate([
            'topup_amount'    => ['required', 'integer', 'min:1', 'max:1000000000'],
            'topup_note'      => ['nullable', 'string', 'max:240'],
            'topup_reference' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $wallets->topup(
                $this->corporate(),
                (int) $this->topup_amount,
                $this->topup_note !== '' ? $this->topup_note : 'Admin top-up',
                $this->topup_reference !== '' ? $this->topup_reference : null,
                auth('admin')->user(),
            );
            $this->reset(['topup_amount', 'topup_note', 'topup_reference']);
            $this->dispatch('notify', title: 'Done', message: 'Wallet topped up.', kind: 'ok');
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    public function adjust(WalletService $wallets): void
    {
        $this->validate([
            'adjust_amount' => ['required', 'integer', 'not_in:0'],
            'adjust_note'   => ['required', 'string', 'max:240'],
        ]);

        try {
            $wallets->adjust(
                $this->corporate(),
                (int) $this->adjust_amount,
                $this->adjust_note,
                auth('admin')->user(),
            );
            $this->reset(['adjust_amount', 'adjust_note']);
            $this->dispatch('notify', title: 'Done', message: 'Adjustment recorded.', kind: 'ok');
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    // ── Rates ──────────────────────────────────────────────────────

    public function addRate(): void
    {
        $data = $this->validate([
            'rate_scope'      => ['required', Rule::in(array_map(fn ($c) => $c->value, CorporateRateScope::cases()))],
            'rate_scope_id'   => ['nullable', 'integer', 'min:1'],
            'rate_type'       => ['required', Rule::in(array_map(fn ($c) => $c->value, CorporateRateType::cases()))],
            'rate_value'      => ['required', 'integer', 'min:0'],
            'rate_starts_at'  => ['nullable', 'date'],
            'rate_ends_at'    => ['nullable', 'date', 'after_or_equal:rate_starts_at'],
            'rate_notes'      => ['nullable', 'string', 'max:5000'],
        ]);

        // Percent off must be 0..100; admin-side guard so weird inputs
        // don't blow up later in PricingCalculator.
        if ($data['rate_type'] === CorporateRateType::PercentOff->value && $data['rate_value'] > 100) {
            $this->addError('rate_value', 'Percent must be between 0 and 100.');

            return;
        }

        $scope = CorporateRateScope::from($data['rate_scope']);
        if ($scope->requiresScopeId() && empty($data['rate_scope_id'])) {
            $this->addError('rate_scope_id', 'Pick which '.$scope->label().' this rate applies to.');

            return;
        }

        CorporateRate::query()->create([
            'corporate_id'  => $this->corporateId,
            'scope'         => $data['rate_scope'],
            'scope_id'      => $scope->requiresScopeId() ? $data['rate_scope_id'] : null,
            'rate_type'     => $data['rate_type'],
            'rate_value'    => $data['rate_value'],
            'starts_at'     => $data['rate_starts_at'] ?: null,
            'ends_at'       => $data['rate_ends_at'] ?: null,
            'notes'         => $data['rate_notes'] ?: null,
        ]);

        $this->reset([
            'rate_scope_id', 'rate_value',
            'rate_starts_at', 'rate_ends_at', 'rate_notes',
        ]);
        $this->dispatch('notify', title: 'Done', message: 'Rate added.', kind: 'ok');
    }

    public function removeRate(int $rateId): void
    {
        CorporateRate::query()
            ->where('id', $rateId)
            ->where('corporate_id', $this->corporateId)
            ->delete();
        $this->dispatch('notify', title: 'Done', message: 'Rate removed.', kind: 'ok');
    }

    // ── Domains ────────────────────────────────────────────────────

    public function addDomain(): void
    {
        $this->validate([
            'domain_value' => ['required', 'string', 'max:120',
                Rule::unique('corporate_domains', 'domain'),
            ],
            'domain_verified' => ['boolean'],
        ]);

        CorporateDomain::query()->create([
            'corporate_id' => $this->corporateId,
            'domain'       => strtolower($this->domain_value),
            'verified_at'  => $this->domain_verified ? now() : null,
        ]);

        $this->reset(['domain_value', 'domain_verified']);
        $this->dispatch('notify', title: 'Done', message: 'Domain added.', kind: 'ok');
    }

    public function toggleDomainVerified(int $domainId): void
    {
        $domain = CorporateDomain::query()
            ->where('id', $domainId)
            ->where('corporate_id', $this->corporateId)
            ->first();
        if ($domain === null) {
            return;
        }
        $domain->verified_at = $domain->verified_at === null ? now() : null;
        $domain->save();
        $this->dispatch('notify', title: 'Done', message: $domain->verified_at !== null ? 'Domain verified.' : 'Verification removed.', kind: 'ok');
    }

    public function removeDomain(int $domainId): void
    {
        CorporateDomain::query()
            ->where('id', $domainId)
            ->where('corporate_id', $this->corporateId)
            ->delete();
        $this->dispatch('notify', title: 'Done', message: 'Domain removed.', kind: 'ok');
    }

    // ── Render ─────────────────────────────────────────────────────

    public function render(WalletService $wallets): View
    {
        $corporate = $this->corporate()->loadMissing(['wallet', 'domains', 'rates']);
        $wallet = $wallets->ensureWallet($corporate);

        return view('livewire.admin.corporates.show', [
            'corporate'     => $corporate,
            'wallet'        => $wallet,
            'members'       => $corporate->members()->latest('id')->limit(100)->get(),
            'transactions'  => $corporate->transactions()->latest('id')->limit(50)->get(),
            'memberRoles'   => CorporateMemberRole::cases(),
            'memberStatuses'=> CorporateMemberStatus::cases(),
            'rateScopes'    => CorporateRateScope::cases(),
            'rateTypes'     => CorporateRateType::cases(),
        ]);
    }

    private function corporate(): Corporate
    {
        return Corporate::query()->findOrFail($this->corporateId);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Corporate;

use App\Enums\CorporateMemberRole;
use App\Enums\CorporateMemberStatus;
use App\Models\CorporateMember;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Corporate admins manage their own roster, invite, suspend,
 * offboard, set per-member caps.
 *
 * Admin-only, non-admin members never reach this page because the
 * sidebar hides it and the constructor guards against direct nav.
 */
#[Layout('layouts.corporate-portal')]
class Members extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    // Invite form
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $employee_id = '';
    public string $role = 'member';
    public ?int $daily_cap_naira = null;
    public ?int $monthly_cap_naira = null;

    public function mount(): void
    {
        $this->guardAdmin();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function invite(): void
    {
        $this->guardAdmin();
        $corporate = auth('corporate')->user()->corporate;

        $data = $this->validate([
            'name'              => ['required', 'string', 'max:160'],
            'email'             => ['required', 'email', 'max:180',
                Rule::unique('corporate_members', 'email')
                    ->where('corporate_id', $corporate->id)
                    ->whereNull('deleted_at'),
            ],
            'phone'             => ['nullable', 'string', 'max:32'],
            'employee_id'       => ['nullable', 'string', 'max:64'],
            'role'              => ['required', Rule::in(['admin', 'member'])],
            'daily_cap_naira'   => ['nullable', 'integer', 'min:0'],
            'monthly_cap_naira' => ['nullable', 'integer', 'min:0'],
        ]);

        // Admin invites mint a one-time password so the new admin can
        // sign in. Regular members don't get one, their identity is
        // proved at booking time by typing the email into the booking form.
        $generated = null;
        $hash = null;
        if ($data['role'] === 'admin') {
            $generated = Str::password(16);
            $hash = Hash::make($generated);
        }

        CorporateMember::query()->create([
            'corporate_id'      => $corporate->id,
            'name'              => $data['name'],
            'email'             => strtolower($data['email']),
            'phone'             => $data['phone'] ?: null,
            'employee_id'       => $data['employee_id'] ?: null,
            'role'              => $data['role'],
            'status'            => CorporateMemberStatus::Active->value,
            'daily_cap_naira'   => $data['daily_cap_naira'] ?? null,
            'monthly_cap_naira' => $data['monthly_cap_naira'] ?? null,
            'invited_at'        => now(),
            'accepted_at'       => now(),
            'password'          => $hash,
        ]);

        $this->reset(['name', 'email', 'phone', 'employee_id', 'daily_cap_naira', 'monthly_cap_naira']);
        $this->role = 'member';

        $this->dispatch('notify', title: 'Done', message: $generated !== null
            ? "Member added. One-time portal password for {$data['email']}: {$generated}"
            : 'Member added.', kind: 'ok');
    }

    public function toggleStatus(int $memberId): void
    {
        $this->guardAdmin();
        $corporate = auth('corporate')->user()->corporate;
        $self = auth('corporate')->user();

        $m = CorporateMember::query()->find($memberId);
        if ($m === null || $m->corporate_id !== $corporate->id) {
            return;
        }
        if ($self !== null && $m->id === $self->getKey() && $m->status === CorporateMemberStatus::Active) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: "You can't suspend your own account.", kind: 'err');

            return;
        }
        $m->status = $m->status === CorporateMemberStatus::Active
            ? CorporateMemberStatus::Suspended
            : CorporateMemberStatus::Active;
        $m->save();
        $this->dispatch('notify', title: 'Done', message: 'Member updated.', kind: 'ok');
    }

    public function offboard(int $memberId): void
    {
        $this->guardAdmin();
        $corporate = auth('corporate')->user()->corporate;
        $self = auth('corporate')->user();

        $m = CorporateMember::query()->find($memberId);
        if ($m === null || $m->corporate_id !== $corporate->id) {
            return;
        }
        if ($self !== null && $m->id === $self->getKey()) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: "You can't offboard yourself.", kind: 'err');

            return;
        }
        $m->status = CorporateMemberStatus::Offboarded;
        $m->offboarded_at = now();
        $m->save();
        $m->delete();
        $this->dispatch('notify', title: 'Done', message: 'Member offboarded.', kind: 'ok');
    }

    public function render(): View
    {
        return view('livewire.portal.corporate.members', [
            'members'        => $this->paginate(),
            'roles'          => CorporateMemberRole::cases(),
            'statuses'       => CorporateMemberStatus::cases(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, CorporateMember>
     */
    private function paginate(): LengthAwarePaginator
    {
        $corporate = auth('corporate')->user()->corporate;
        $q = CorporateMember::query()
            ->where('corporate_id', $corporate->id)
            ->orderByDesc('id');

        $needle = trim($this->search);
        if ($needle !== '') {
            $q->where(function ($qq) use ($needle): void {
                $qq->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('email', 'like', '%'.$needle.'%')
                    ->orWhere('employee_id', 'like', '%'.$needle.'%');
            });
        }
        if ($this->statusFilter !== '' && CorporateMemberStatus::tryFrom($this->statusFilter) !== null) {
            $q->where('status', $this->statusFilter);
        }

        return $q->paginate(20);
    }

    private function guardAdmin(): void
    {
        $member = auth('corporate')->user();
        if ($member === null || $member->role !== CorporateMemberRole::Admin) {
            throw new AccessDeniedHttpException('Corporate admin access required.');
        }
    }
}

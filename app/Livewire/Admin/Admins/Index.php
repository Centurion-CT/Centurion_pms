<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Admins;

use App\Models\Admin;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Platform-admin CRUD list (PRD §18). Lifts no tenant scope, admins
 * are central. Only `super` admins can mutate other admins; `support`
 * sees the list read-only.
 */
#[Layout('admin.layout')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'role')]
    public string $roleFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function toggleActive(int $id): void
    {
        if (! $this->isSuper()) {
            return;
        }

        /** @var Admin|null $admin */
        $admin = Admin::query()->find($id);
        if ($admin === null) {
            return;
        }

        $current = auth('admin')->user();
        // Refuse self-deactivation. A super-admin shouldn't be able to
        // lock themselves out of the panel; they have to ask another
        // super to do it.
        if ($current !== null && $admin->id === $current->getKey() && $admin->is_active) {
            session()->flash('error', "You can't deactivate your own account, ask another super admin.");

            return;
        }

        // Block deactivating the last live super admin so the platform
        // always has at least one operational owner.
        if ($admin->is_active && $admin->role === 'super' && $this->liveSuperCount() <= 1) {
            session()->flash('error', "Can't deactivate the last super admin.");

            return;
        }

        $admin->is_active = ! $admin->is_active;
        $admin->save();

        activity('admin')
            ->causedBy($current)
            ->performedOn($admin)
            ->event($admin->is_active ? 'reactivated' : 'deactivated')
            ->log($admin->is_active ? 'Reactivated admin' : 'Deactivated admin');

        session()->flash('status', $admin->is_active ? 'Admin reactivated.' : 'Admin deactivated.');
    }

    public function delete(int $id): void
    {
        if (! $this->isSuper()) {
            return;
        }

        /** @var Admin|null $admin */
        $admin = Admin::query()->find($id);
        if ($admin === null) {
            return;
        }

        $current = auth('admin')->user();
        if ($current !== null && $admin->id === $current->getKey()) {
            session()->flash('error', "You can't delete your own account.");

            return;
        }
        if ($admin->role === 'super' && $this->liveSuperCount() <= 1) {
            session()->flash('error', "Can't delete the last super admin.");

            return;
        }

        activity('admin')
            ->causedBy($current)
            ->performedOn($admin)
            ->event('deleted')
            ->withProperties(['email' => $admin->email, 'role' => $admin->role])
            ->log('Deleted admin');

        $admin->delete();

        session()->flash('status', 'Admin removed.');
    }

    public function render(): View
    {
        return view('livewire.admin.admins.index', [
            'admins'  => $this->paginate(),
            'isSuper' => $this->isSuper(),
        ]);
    }

    private function isSuper(): bool
    {
        return auth('admin')->user()?->role === 'super';
    }

    private function liveSuperCount(): int
    {
        return Admin::query()
            ->where('role', 'super')
            ->where('is_active', true)
            ->count();
    }

    /**
     * @return LengthAwarePaginator<int, Admin>
     */
    private function paginate(): LengthAwarePaginator
    {
        $query = Admin::query()->orderBy('id', 'desc');

        $needle = trim($this->search);
        if ($needle !== '') {
            $query->where(function ($q) use ($needle): void {
                $q->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('email', 'like', '%'.$needle.'%');
            });
        }
        if ($this->roleFilter !== '') {
            $query->where('role', $this->roleFilter);
        }
        if ($this->statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        return $query->paginate(20);
    }
}

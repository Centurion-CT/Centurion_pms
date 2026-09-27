<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Admins;

use App\Models\Admin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Create or edit a platform admin. Super-admin only.
 *
 * On create the operator can either pick a password or leave it blank
 *, in which case we mint a long random one and surface it once in the
 * flash banner so the inviter can share it via a side channel.
 *
 * On edit a blank password leaves the existing hash alone; the operator
 * can still change name + role + active flag.
 */
#[Layout('admin.layout')]
class Form extends Component
{
    #[Locked]
    public ?int $adminId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'support';

    public bool $is_active = true;

    public string $password = '';

    public function mount(?Admin $admin = null): void
    {
        $current = auth('admin')->user();
        if ($current === null || $current->role !== 'super') {
            throw new AccessDeniedHttpException('Only super admins can manage admin accounts.');
        }

        if ($admin !== null && $admin->exists) {
            $this->adminId   = $admin->id;
            $this->name      = $admin->name;
            $this->email     = $admin->email;
            $this->role      = $admin->role;
            $this->is_active = $admin->is_active;
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:120'],
            'email'     => ['required', 'email', 'max:180',
                Rule::unique('admins', 'email')->ignore($this->adminId),
            ],
            'role'      => ['required', Rule::in(['super', 'support'])],
            'is_active' => ['boolean'],
            // Optional on edit; required on create.
            'password'  => $this->adminId === null
                ? ['nullable', 'string', 'min:12']
                : ['nullable', 'string', 'min:12'],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        $current = auth('admin')->user();
        $isCreate = $this->adminId === null;

        /** @var Admin $admin */
        $admin = $isCreate ? new Admin : Admin::query()->findOrFail($this->adminId);

        // Refuse to demote / deactivate the last live super admin.
        if (! $isCreate) {
            $wasLiveSuper = $admin->role === 'super' && $admin->is_active;
            $willBeLiveSuper = $data['role'] === 'super' && (bool) $data['is_active'];
            if ($wasLiveSuper && ! $willBeLiveSuper
                && Admin::query()->where('role', 'super')->where('is_active', true)->count() <= 1) {
                $this->addError('role', 'Cannot demote or deactivate the last super admin.');

                return;
            }
        }

        $admin->fill([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'role'      => $data['role'],
            'is_active' => (bool) $data['is_active'],
        ]);

        $generatedPassword = null;
        if ($isCreate) {
            // Generate a strong password if the inviter didn't supply
            // one, surfaced via flash so they can share once.
            if (($data['password'] ?? '') === '') {
                $generatedPassword = Str::password(20);
                $admin->password = Hash::make($generatedPassword);
            } else {
                $admin->password = Hash::make($data['password']);
            }
        } elseif (($data['password'] ?? '') !== '') {
            $admin->password = Hash::make($data['password']);
        }

        $admin->save();

        activity('admin')
            ->causedBy($current)
            ->performedOn($admin)
            ->event($isCreate ? 'created' : 'updated')
            ->withProperties([
                'email' => $admin->email,
                'role'  => $admin->role,
            ])
            ->log($isCreate ? 'Invited admin' : 'Updated admin');

        if ($generatedPassword !== null) {
            session()->flash('status', "Admin created. One-time password for {$admin->email}: {$generatedPassword}");
        } else {
            session()->flash('status', $isCreate ? 'Admin created.' : 'Admin updated.');
        }

        $this->redirectRoute('admin.admins.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.admins.form', [
            'isEdit' => $this->adminId !== null,
        ]);
    }
}

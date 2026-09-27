<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Staff;

use App\Enums\OperatorRole;
use App\Mail\Operators\OperatorInviteMail;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Staff / user management, every operator on the current tenant, with
 * an invite form. Lives at its own top-level route because managing
 * people is a distinct job from tweaking workspace settings; nesting
 * it under /settings buried it.
 *
 * Invite delivery: the operator record is created but sits in an
 * un-activated state (activated_at NULL, unusable password hash).
 * Tenant + central login both refuse to sign in un-activated
 * accounts, the ONLY path in is the signed 24-hour magic link
 * emailed to the invitee, which takes them to /invite/activate to
 * pick a password. On save that page stamps activated_at + writes
 * the password to both the Operator and its matching central
 * IdentityAccount so keverna.com/login recognizes them from that
 * moment on. IdentityAccount + IdentityOperatorLink are created
 * here at invite time so central login has something to hand off
 * to a tenant subdomain the moment activation lands.
 *
 * Guards: self-removal is blocked, and the last remaining manager
 * cannot be demoted or removed, an unmanageable tenant is worse
 * than an inconvenient one.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    public string $inviteName = '';
    public string $inviteEmail = '';
    public string $inviteRole = 'frontdesk';

    public function invite(): void
    {
        $data = $this->validate([
            'inviteName' => ['required', 'string', 'max:120'],
            'inviteEmail' => [
                'required', 'email', 'max:180',
                Rule::unique('operators', 'email'),
            ],
            'inviteRole' => ['required', Rule::in(array_map(fn ($r) => $r->value, OperatorRole::cases()))],
        ]);

        $tenant = Tenant::current();
        if ($tenant === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Tenant context unavailable.', kind: 'err');

            return;
        }

        // Unusable password hash, never revealed to anyone, so no
        // login attempt can succeed against it. The activation page
        // replaces it with the invitee's chosen password (and
        // simultaneously stamps activated_at). Kept non-null so any
        // future code that assumes `password` is populated won't
        // trip.
        $sentinelPassword = Hash::make(Str::random(48));

        // 32-char URL-safe nonce for the magic link. We keep the
        // bcrypt hash on the operator + the plaintext only long
        // enough to build the signed URL for the email, never store
        // the plaintext, never log it.
        $magicToken = Str::random(32);
        $inviteExpiresAt = Carbon::now()->addHours(24);
        $email = IdentityAccount::normaliseEmail($data['inviteEmail']);

        [$operator, $identity] = DB::transaction(function () use ($tenant, $data, $email, $sentinelPassword, $magicToken, $inviteExpiresAt) {
            $operator = Operator::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['inviteName'],
                'email' => $email,
                'password' => $sentinelPassword,
                'role' => $data['inviteRole'],
                'invite_token_hash' => Hash::make($magicToken),
                'invite_expires_at' => $inviteExpiresAt,
                // activated_at stays NULL until the invitee saves a
                // password on /invite/activate.
            ]);

            // Central identity, reuse an existing one (someone was
            // already invited under this email to a different tenant),
            // or create a fresh account with the same unusable hash.
            $identity = IdentityAccount::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $data['inviteName'], 'password' => $sentinelPassword],
            );

            // Link identity ↔ operator so central keverna.com/login,
            // once the invitee activates, hands off cleanly to this
            // tenant's dashboard.
            IdentityOperatorLink::query()->firstOrCreate(
                [
                    'identity_account_id' => $identity->id,
                    'tenant_id' => $tenant->id,
                ],
                ['operator_id' => $operator->id],
            );

            return [$operator, $identity];
        });

        $magicLink = URL::temporarySignedRoute(
            'tenant.operator-invite.claim',
            $inviteExpiresAt,
            ['operator' => $operator->id, 'token' => $magicToken],
        );

        $inviterName = auth('web')->user()?->name ?? 'A Keverna manager';

        Mail::to($operator->email)->queue(new OperatorInviteMail(
            operator: $operator,
            tenant: $tenant,
            inviterName: $inviterName,
            roleLabel: OperatorRole::from($data['inviteRole'])->label(),
            magicLink: $magicLink,
        ));

        $this->dispatch('notify', title: 'Done', message: sprintf(
            'Invite email sent to %s. Their account activates the moment they click the link and pick a password, the link is good for 24 hours.',
            $email,
        ), kind: 'ok');

        $this->inviteName = '';
        $this->inviteEmail = '';
        $this->inviteRole = 'frontdesk';
    }

    public function updateRole(int $operatorId, string $role): void
    {
        $newRole = OperatorRole::tryFrom($role);
        if ($newRole === null) {
            return;
        }

        $current = auth('web')->user();
        if ($current === null) {
            return;
        }
        if (($current->role ?? OperatorRole::Manager) !== OperatorRole::Manager) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Only managers can change roles.', kind: 'err');

            return;
        }
        if ($current->id === $operatorId && $newRole !== OperatorRole::Manager) {
            $tenant = Tenant::current();
            $otherManagers = Operator::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant?->id)
                ->where('id', '!=', $current->id)
                ->where('role', OperatorRole::Manager->value)
                ->count();
            if ($otherManagers === 0) {
                $this->dispatch('notify', title: 'Couldn\'t complete', message: 'At least one manager is required. Promote someone else first.', kind: 'err');

                return;
            }
        }

        Operator::query()
            ->where('id', $operatorId)
            ->update(['role' => $newRole->value]);

        $this->dispatch('notify', title: 'Done', message: 'Role updated.', kind: 'ok');
    }

    public function remove(int $operatorId): void
    {
        if (($op = $this->guardActionable($operatorId, requireLastManagerCheck: true)) === null) {
            return;
        }

        // Hard delete is only offered on Pending (never activated)
        // and Revoked operators, that's the "clean up dead rows"
        // path. Active + Suspended must be revoked first; the UI
        // hides the button in those states but the server-side
        // guard is what actually enforces it.
        $isPending = $op->activated_at === null;
        if (! $isPending && ! $op->isRevoked()) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Revoke this operator first, then delete.', kind: 'err');

            return;
        }

        $tenant = Tenant::current();
        $remaining = Operator::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant?->id)
            ->count();
        if ($remaining <= 1) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'A property must have at least one operator. Invite another first.', kind: 'err');

            return;
        }

        // Also drop the identity → operator link so central login
        // doesn't reference a ghost operator. The IdentityAccount
        // row stays (it may be linked to other tenants).
        IdentityOperatorLink::query()
            ->where('operator_id', $op->id)
            ->where('tenant_id', $tenant?->id)
            ->delete();

        $displayName = $op->name;
        $op->delete();
        $this->dispatch('notify', title: 'Done', message: $displayName.' deleted.', kind: 'ok');
    }

    /* ────────────────────────────────────────────────────────────
       Access lifecycle: suspend / unsuspend / revoke / unrevoke.
       All manager-gated, all reject touching yourself, all
       protect the last remaining manager.
       ──────────────────────────────────────────────────────────── */

    public function suspend(int $operatorId): void
    {
        if (($op = $this->guardActionable($operatorId)) === null) {
            return;
        }
        if ($op->isSuspended()) {
            return;
        }
        $op->forceFill(['suspended_at' => now()])->save();
        $this->dispatch('notify', title: 'Done', message: $op->name.' is suspended. They cannot sign in until you restore access.', kind: 'ok');
    }

    public function unsuspend(int $operatorId): void
    {
        if (($op = $this->guardActionable($operatorId, allowSelfIfManager: true)) === null) {
            return;
        }
        if (! $op->isSuspended()) {
            return;
        }
        $op->forceFill(['suspended_at' => null])->save();
        $this->dispatch('notify', title: 'Done', message: 'Access restored for '.$op->name.'.', kind: 'ok');
    }

    public function revoke(int $operatorId): void
    {
        if (($op = $this->guardActionable($operatorId, requireLastManagerCheck: true)) === null) {
            return;
        }
        if ($op->isRevoked()) {
            return;
        }
        $op->forceFill(['revoked_at' => now()])->save();
        $this->dispatch('notify', title: 'Done', message: $op->name.'\'s access has been revoked.', kind: 'ok');
    }

    public function unrevoke(int $operatorId): void
    {
        if (($op = $this->guardActionable($operatorId, allowSelfIfManager: true)) === null) {
            return;
        }
        if (! $op->isRevoked()) {
            return;
        }
        $op->forceFill(['revoked_at' => null])->save();
        $this->dispatch('notify', title: 'Done', message: 'Access re-granted to '.$op->name.'.', kind: 'ok');
    }

    /* ────────────────────────────────────────────────────────────
       Resend invite: mints a fresh magic-link nonce + 24h expiry
       + queues a new OperatorInviteMail. Only applies to operators
       still pending activation.
       ──────────────────────────────────────────────────────────── */

    public function resendInvite(int $operatorId): void
    {
        if (($op = $this->guardActionable($operatorId)) === null) {
            return;
        }
        if ($op->activated_at !== null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $op->name.' has already activated their account, nothing to resend.', kind: 'err');

            return;
        }

        $tenant = Tenant::current();
        if ($tenant === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Tenant context unavailable.', kind: 'err');

            return;
        }

        $magicToken = Str::random(32);
        $inviteExpiresAt = Carbon::now()->addHours(24);
        $op->forceFill([
            'invite_token_hash' => Hash::make($magicToken),
            'invite_expires_at' => $inviteExpiresAt,
        ])->save();

        $magicLink = URL::temporarySignedRoute(
            'tenant.operator-invite.claim',
            $inviteExpiresAt,
            ['operator' => $op->id, 'token' => $magicToken],
        );

        $inviterName = auth('web')->user()?->name ?? 'A Keverna manager';
        Mail::to($op->email)->queue(new OperatorInviteMail(
            operator: $op,
            tenant: $tenant,
            inviterName: $inviterName,
            roleLabel: ($op->role ?? OperatorRole::Frontdesk)->label(),
            magicLink: $magicLink,
        ));

        $this->dispatch('notify', title: 'Done', message: 'Fresh invite emailed to '.$op->email.'. The old link is retired.', kind: 'ok');
    }

    /* ────────────────────────────────────────────────────────────
       Permissions live on a dedicated page, see the OperatorPermissions
       Livewire at /dashboard/staff/{operator}/permissions.
       ──────────────────────────────────────────────────────────── */

    /**
     * Shared guard for every access-lifecycle action. Returns the
     * target operator when the action is allowed; null (with
     * $errorMessage set) when it's blocked.
     *
     * `allowSelfIfManager`: some actions (unsuspend, unrevoke,
     * updatePermissions) are safe to run on yourself in edge cases
     *, e.g. a manager restoring their own accidentally-revoked
     * access via a manual DB fix. Others (suspend, revoke) never
     * make sense on self.
     *
     * `requireLastManagerCheck`: revoke removes the operator from
     * the signable pool, the last-manager guard prevents locking
     * every manager out of the property.
     */
    private function guardActionable(
        int $operatorId,
        bool $allowSelfIfManager = false,
        bool $requireLastManagerCheck = false,
    ): ?Operator {
        $current = auth('web')->user();
        if ($current === null) {
            return null;
        }
        if (($current->role ?? OperatorRole::Manager) !== OperatorRole::Manager) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Only managers can manage staff access.', kind: 'err');

            return null;
        }
        if (! $allowSelfIfManager && $current->id === $operatorId) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'You can\'t apply that action to your own account.', kind: 'err');

            return null;
        }

        $tenant = Tenant::current();
        /** @var Operator|null $op */
        $op = Operator::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant?->id)
            ->where('id', $operatorId)
            ->first();
        if ($op === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Operator not found.', kind: 'err');

            return null;
        }

        if ($requireLastManagerCheck && $op->role === OperatorRole::Manager) {
            $otherActiveManagers = Operator::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant?->id)
                ->where('id', '!=', $op->id)
                ->where('role', OperatorRole::Manager->value)
                ->whereNull('suspended_at')
                ->whereNull('revoked_at')
                ->count();
            if ($otherActiveManagers === 0) {
                $this->dispatch('notify', title: 'Couldn\'t complete', message: 'At least one active manager is required. Restore or promote another manager first.', kind: 'err');

                return null;
            }
        }

        return $op;
    }

    public function render(): View
    {
        $tenant = Tenant::current();
        $operators = Operator::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant?->id)
            ->orderBy('id')
            ->get();

        return view('livewire.dashboard.staff.index', [
            'operators' => $operators,
            'currentOperatorId' => auth('web')->id(),
            'roles' => OperatorRole::cases(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Auth;

use App\Http\Controllers\Tenant\OperatorInviteClaimController;
use App\Models\IdentityAccount;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Invite activation: the invitee's ONLY path in.
 *
 * Reached from OperatorInviteClaimController after the magic link
 * checks out. Gated by a session-scoped pending-operator-id key;
 * hitting this page without a valid claim just bounces back to
 * /login.
 *
 * On save: writes the picked password to the Operator AND its
 * matching central IdentityAccount (so keverna.com/login recognizes
 * the invitee from that moment on), stamps activated_at, logs the
 * operator into the `web` guard, and lands them on the dashboard.
 */
#[Layout('layouts.tenant-auth')]
class ActivateInvite extends Component
{
    public ?int $pendingOperatorId = null;

    public string $inviteeName = '';
    public string $inviteeEmail = '';
    public string $tenantName = '';

    #[Validate]
    public string $password = '';

    #[Validate('required|same:password')]
    public string $password_confirmation = '';

    /**
     * Password rules, same 8 char + letters + numbers we use on
     * the profile-page password change so the two flows can't drift.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'password_confirmation' => ['required'],
        ];
    }

    public function mount(): void
    {
        // Belt-and-braces: the claim controller already logs any
        // existing web session out before redirecting here. If
        // something reached this page with a stale session anyway
        // (direct visit, cookie leak) drop it now so the tenant-auth
        // layout doesn't wrap this activation form in a dashboard
        // sidebar meant for the currently-authenticated operator.
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            session()->regenerate();
        }

        $this->pendingOperatorId = (int) session()->get(OperatorInviteClaimController::SESSION_KEY);
        if ($this->pendingOperatorId <= 0) {
            $this->redirect(route('tenant.login'), navigate: false);

            return;
        }

        $op = Operator::query()->find($this->pendingOperatorId);
        $tenant = Tenant::current();
        if ($op === null || $tenant === null || $op->tenant_id !== $tenant->id) {
            // The session id points at nothing (rare, someone with
            // an invite from tenant A visiting tenant B's subdomain,
            // or the operator was hard-deleted mid-claim). Clear the
            // stale key so a refresh doesn't loop.
            session()->forget(OperatorInviteClaimController::SESSION_KEY);
            $this->redirect(route('tenant.login'), navigate: false);

            return;
        }

        // Already-activated: someone (invitee, or a manager who
        // manually saved a password) has already run through the
        // flow. Drop the session key and bounce to /login so they
        // can just sign in.
        if ($op->activated_at !== null) {
            session()->forget(OperatorInviteClaimController::SESSION_KEY);
            session()->flash('status', 'Your account is already activated, sign in with the password you picked.');
            $this->redirect(route('tenant.login'), navigate: false);

            return;
        }

        // Prefill the read-only header so the invitee sees "who am I
        // activating", mostly useful when the link was forwarded.
        $this->inviteeName = $op->name;
        $this->inviteeEmail = $op->email;
        $this->tenantName = $tenant->name;
    }

    public function activate(): void
    {
        $this->validate();

        $op = Operator::query()->find($this->pendingOperatorId);
        $tenant = Tenant::current();
        if ($op === null || $tenant === null || $op->tenant_id !== $tenant->id) {
            $this->redirect(route('tenant.login'), navigate: false);

            return;
        }

        $newHash = Hash::make($this->password);
        $now = now();

        DB::transaction(function () use ($op, $newHash, $now): void {
            $op->forceFill([
                'password' => $newHash,
                'activated_at' => $now,
                // Clicking the signed magic link is proof the invitee
                // controls the invite email, no separate verification
                // needed. Same rationale skips the phone step: they
                // were vouched for by a manager on this tenant, not
                // walk-in customers we need to reach at OTP time.
                'email_verified_at' => $op->email_verified_at ?? $now,
                'phone_verification_skipped_at' => $op->phone_verification_skipped_at ?? $now,
                'last_login_at' => $now,
                'invite_token_hash' => null,
                'invite_expires_at' => null,
                'force_password_change' => false,
            ])->save();

            // Mirror the password onto the central identity so
            // keverna.com/login works with the same credentials.
            // (Rows always exist, Staff/Index::invite() creates
            // both sides in one transaction.)
            IdentityAccount::query()
                ->where('email', $op->email)
                ->update(['password' => $newHash]);
        });

        session()->forget(OperatorInviteClaimController::SESSION_KEY);
        Auth::guard('web')->login($op, remember: false);
        session()->regenerate();

        $this->redirect(route('tenant.onboarding'), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.tenant.auth.activate-invite');
    }
}

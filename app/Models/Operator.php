<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Database\Factories\OperatorFactory;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $phone_verification_skipped_at
 * @property string $password
 * @property Carbon|null $last_login_at
 * @property \App\Enums\OperatorRole|null $role
 * @property-read Tenant $tenant
 */
class Operator extends Authenticatable implements CanResetPasswordContract, MustVerifyEmailContract
{
    use BelongsToTenant;

    /** @use HasFactory<OperatorFactory> */
    use HasFactory;

    // Sanctum-issued personal access tokens power the Flutter mobile
    // app. Web still uses session auth via the `web` guard; the token
    // ability is scoped per-device (via MobileDevice) so a revoked
    // phone is a one-row soft-delete without touching web sessions.
    use HasApiTokens;

    use Notifiable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'invite_token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'phone_verification_skipped_at' => 'datetime',
            'last_login_at' => 'datetime',
            'invite_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'revoked_at' => 'datetime',
            'permissions' => 'array',
            'preferences' => 'array',
            'force_password_change' => 'boolean',
            'password' => 'hashed',
            'role' => \App\Enums\OperatorRole::class,
            'last_notifications_seen_at' => 'datetime',
        ];
    }

    /**
     * Sidebar layout the operator has picked in
     * settings > profile ("tiles" | "list"). Defaults to "tiles"
     * (the historic view) when the operator has never touched the
     * preference — matches the fallback the tenant layout renders
     * for unauthenticated pages.
     */
    public function sidebarVariant(): string
    {
        // Install default comes from config/brand.php (Centurion ships
        // the 3A list); an operator's own pick always wins.
        $default = in_array(config('brand.sidebar_default'), ['tiles', 'list'], true)
            ? config('brand.sidebar_default')
            : 'tiles';
        $v = $this->preferences['sidebar_variant'] ?? $default;
        return in_array($v, ['tiles', 'list'], true) ? $v : $default;
    }

    /** True if the operator can still sign in. */
    public function canSignIn(): bool
    {
        return $this->activated_at !== null
            && $this->suspended_at === null
            && $this->revoked_at === null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * True if the operator has been granted the given permission on
     * top of their role. Roles do NOT satisfy permission checks — a
     * Manager checking `hasPermission('prices.manage')` returns false
     * even though Managers can manage prices via their role. Callers
     * that want the combined check should use canManagePrices() /
     * canAccessModule() which union both.
     */
    public function hasPermission(\App\Enums\Permission|string $permission): bool
    {
        $key = $permission instanceof \App\Enums\Permission ? $permission->value : $permission;
        $granted = (array) ($this->permissions ?? []);

        return in_array($key, $granted, true);
    }

    /**
     * Modules unlocked by the operator's granted permissions (module-
     * unlock permissions only — capability grants like prices.manage
     * return null from ->module() and are excluded here).
     *
     * @return list<string>
     */
    public function grantedModules(): array
    {
        $modules = [];
        foreach ((array) ($this->permissions ?? []) as $key) {
            $perm = \App\Enums\Permission::tryFrom((string) $key);
            $module = $perm?->module();
            if ($module !== null) {
                $modules[] = $module;
            }
        }

        return array_values(array_unique($modules));
    }

    /**
     * Union of role-based module access + module-unlock permission
     * grants. This is the check every route middleware + the sidebar
     * layout should call.
     */
    public function canAccessModule(string $module): bool
    {
        $role = $this->role ?? \App\Enums\OperatorRole::Manager;
        if ($role->canAccessModule($module)) {
            return true;
        }

        return in_array($module, $this->grantedModules(), true);
    }

    /**
     * True if the operator can edit prices. Manager role satisfies
     * this by default; a non-manager can be granted PricesManage
     * to lift the gate.
     */
    public function canManagePrices(): bool
    {
        $role = $this->role ?? \App\Enums\OperatorRole::Manager;

        return $role->canManagePrices() || $this->hasPermission(\App\Enums\Permission::PricesManage);
    }

    protected static function newFactory(): OperatorFactory
    {
        return OperatorFactory::new();
    }

    /**
     * Outlets this operator can run the POS terminal for. Manager role
     * bypasses this list — they see every outlet. PosStaff must have
     * an entry here to see anything at all.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Outlet, $this>
     */
    public function outlets(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'outlet_operators')
            ->withTimestamps();
    }

    /**
     * Branches (properties) this operator is explicitly granted
     * access to. Empty pivot = "all branches" (backwards-compat
     * default for orgs pre-multi-property); once an admin ticks
     * any branch on the Staff permissions page, the operator is
     * restricted to that set. Manager role bypasses this via
     * {@see hasAccessToBranch()} — a manager can always see every
     * branch regardless of pivot rows.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Property, $this>
     */
    public function branches(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Property::class, 'operator_properties', 'operator_id', 'property_id')
            ->withTimestamps();
    }

    /**
     * True when this operator can work in the given branch. Managers
     * always can. Non-managers pass when they have no pivot rows (the
     * "all-access" default) OR when the branch is in their pivot set.
     */
    public function hasAccessToBranch(\App\Models\Property $property): bool
    {
        if ($this->role === \App\Enums\OperatorRole::Manager) {
            return true;
        }
        $count = $this->branches()->count();
        if ($count === 0) {
            return true;
        }
        return $this->branches()->where('properties.id', $property->getKey())->exists();
    }

    /**
     * Returns the property ids the operator can work in. Empty array
     * carries a special meaning — "no restriction, sees everything the
     * tenant owns" — so call sites must distinguish [] from a set of
     * specific ids. See {@see \App\Services\Properties\PropertyContext::all()}
     * for the canonical consumer.
     *
     * @return array<int, int>
     */
    public function accessibleBranchIds(): array
    {
        if ($this->role === \App\Enums\OperatorRole::Manager) {
            return [];
        }
        return $this->branches()->pluck('properties.id')->all();
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Onboarding-gate check: true if the operator has either verified their
     * phone or explicitly deferred that step. Used by RequireCompleteOnboarding
     * so a broken Sendpipe/WhatsApp delivery can't strand them at step 3.
     */
    public function hasResolvedPhoneStep(): bool
    {
        return $this->phone_verified_at !== null
            || $this->phone_verification_skipped_at !== null;
    }

    /**
     * Mirrors Laravel's MustVerifyEmail::markEmailAsVerified() but for the
     * phone channel (PRD §6.1.1 step 3). Used by the OTP service.
     */
    public function markPhoneAsVerified(): bool
    {
        return $this->forceFill(['phone_verified_at' => now()])->save();
    }

    public function markPhoneVerificationSkipped(): bool
    {
        return $this->forceFill(['phone_verification_skipped_at' => now()])->save();
    }
}

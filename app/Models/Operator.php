<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperatorRole;
use App\Enums\Permission;
use App\Multitenancy\BelongsToTenant;
use App\Services\Properties\PropertyContext;
use Database\Factories\OperatorFactory;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
 * @property OperatorRole|null $role
 * @property-read Tenant $tenant
 */
class Operator extends Authenticatable implements CanResetPasswordContract, MustVerifyEmailContract
{
    use BelongsToTenant;

    // Sanctum-issued personal access tokens power the Flutter mobile
    // app. Web still uses session auth via the `web` guard; the token
    // ability is scoped per-device (via MobileDevice) so a revoked
    // phone is a one-row soft-delete without touching web sessions.
    use HasApiTokens;

    /** @use HasFactory<OperatorFactory> */
    use HasFactory;
    use Notifiable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'staff_pin_hash',
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
            'role' => OperatorRole::class,
            'last_notifications_seen_at' => 'datetime',
            'staff_pin_set_at' => 'datetime',
        ];
    }

    /**
     * Staff app sign-in PIN (4 to 6 digits), stored hashed. Set by a
     * manager on the Staff page; the phone number is the username.
     */
    public function setStaffPin(?string $pin): void
    {
        $this->forceFill([
            'staff_pin_hash' => $pin === null ? null : Hash::make($pin),
            'staff_pin_set_at' => $pin === null ? null : now(),
        ])->save();
    }

    /**
     * Housekeepers / porters added with phone + PIN only. Their email is
     * a placeholder on the reserved .invalid domain that is never mailed
     * or shown.
     */
    public function isAppOnly(): bool
    {
        return str_ends_with((string) $this->email, '@staff.invalid');
    }

    public static function appOnlyEmail(): string
    {
        return 'app-'.strtolower((string) Str::ulid()).'@staff.invalid';
    }

    public function hasStaffPin(): bool
    {
        return $this->staff_pin_hash !== null;
    }

    public function checkStaffPin(string $pin): bool
    {
        return $this->staff_pin_hash !== null && Hash::check($pin, $this->staff_pin_hash);
    }

    /**
     * Comparable form of a Nigerian phone number: digits only, with
     * +234 / 234 folded to a leading 0, so "+234 803 123 4567",
     * "2348031234567" and "0803-123-4567" all match. Null when empty.
     */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            $digits = '0'.substr($digits, 3);
        } elseif (strlen($digits) === 10 && ! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        return $digits === '' ? null : $digits;
    }

    public function firstName(): string
    {
        return (string) strtok(trim((string) $this->name), ' ');
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
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
    public function hasPermission(Permission|string $permission): bool
    {
        $key = $permission instanceof Permission ? $permission->value : $permission;
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
            $perm = Permission::tryFrom((string) $key);
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
        $role = $this->role ?? OperatorRole::Manager;
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
        $role = $this->role ?? OperatorRole::Manager;

        return $role->canManagePrices() || $this->hasPermission(Permission::PricesManage);
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
     * @return BelongsToMany<Outlet, $this>
     */
    public function outlets(): BelongsToMany
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
     * @return BelongsToMany<Property, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'operator_properties', 'operator_id', 'property_id')
            ->withTimestamps();
    }

    /**
     * True when this operator can work in the given branch. Managers
     * always can. Non-managers pass when they have no pivot rows (the
     * "all-access" default) OR when the branch is in their pivot set.
     */
    public function hasAccessToBranch(Property $property): bool
    {
        if ($this->role === OperatorRole::Manager) {
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
     * specific ids. See {@see PropertyContext::all()}
     * for the canonical consumer.
     *
     * @return array<int, int>
     */
    public function accessibleBranchIds(): array
    {
        if ($this->role === OperatorRole::Manager) {
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

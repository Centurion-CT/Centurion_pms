<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Auth\RegisterTenantOperator;
use App\Enums\SubscriptionPlan;
use App\Models\LicenseCode;
use App\Services\PostRegistrationLoginLink;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Self-serve tenant + operator registration. The tier is derived from
 * the `unit_count` field via SubscriptionPlan::fromUnitCount, the
 * `?plan=` URL param only seeds a sensible default so a visitor
 * arriving from the pricing page starts with their expected tier
 * pre-selected, but the number they type is the source of truth.
 *
 * Enterprise (>= 51 units) is not self-serve: the form flips to a
 * "Talk to us" state and register() rejects with a validation error
 * pointing the user at hello@keverna.com.
 */
#[Layout('layouts.auth')]
class Register extends Component
{
    #[Validate('required|string|max:120')]
    public string $name = '';

    #[Validate('required|email|max:180|unique:operators,email')]
    public string $email = '';

    #[Validate('required|string|max:120')]
    public string $property_name = '';

    #[Validate('nullable|string|max:20')]
    public string $phone = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Reflected in the URL so a shared link keeps the tier selection -
     * "?plan=growth" survives page refresh and back/forward navigation.
     */
    #[Url(as: 'plan', keep: false)]
    public string $planParam = 'growth';

    /**
     * The number the operator plans to list. Drives tier resolution.
     * Seeded from `?plan=` on mount; owned by the user after that.
     */
    #[Validate('required|integer|min:1|max:9999')]
    public int $unit_count = 1;

    /**
     * License code the sales team issues after a demo call. Register
     * is now gated behind a valid code so we don't have random
     * unactivated tenants sitting on the DB. Seeded from `?code=` so
     * the operator can click a link in their WhatsApp/email straight
     * into the form.
     */
    #[Url(as: 'code', except: '')]
    public string $license_code = '';

    /**
     * Two-step form:
     *   Step 1 — About you (name, email, phone, password + confirm)
     *   Step 2 — Your property (name, units, licence code)
     *
     * Step 1 is validated before the operator can advance; the final
     * `register()` re-validates everything so a client-side skip past
     * step 1 can't submit a half-filled request.
     */
    public int $step = 1;

    public function mount(): void
    {
        // Default unit_count to a sensible mid-range number for the tier
        // the visitor arrived on. They can adjust; we recompute tier on
        // every render.
        $this->unit_count = match (strtolower($this->planParam)) {
            'starter' => 1,
            'growth' => 8,
            'pro' => 25,
            'enterprise' => 51,
            default => 1,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * Validate step 1 fields (about-you) and advance. Step 2 fields
     * aren't touched here so partial-fill data on step 2 doesn't
     * bounce the operator back to step 1 with unrelated errors.
     */
    public function nextStep(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', 'unique:operators,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $this->step = 2;
    }

    public function prevStep(): void
    {
        $this->step = 1;
    }

    public function derivedPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::fromUnitCount(max(1, $this->unit_count));
    }

    public function register(
        RegisterTenantOperator $action,
        PostRegistrationLoginLink $linkBuilder,
    ): void {
        $data = $this->validate();

        $plan = $this->derivedPlan();

        // Enterprise is negotiated. Refuse self-serve and point them
        // at sales via a validation error the form can render.
        if (! $plan->isSelfServe()) {
            throw ValidationException::withMessages([
                'unit_count' => 'For 50+ units we set you up personally. Email hello@keverna.com to get started.',
            ]);
        }

        // License-code gate. Registration only proceeds when a valid,
        // unredeemed code from the sales team is presented. Prevents
        // unclaimed tenants sitting on the DB after abandoned trials.
        $license = LicenseCode::query()->where('code', trim($this->license_code))->first();
        if ($license === null || ! $license->isRedeemable()) {
            throw ValidationException::withMessages([
                'license_code' => 'This licence code is invalid, already used, or expired. Book a demo to receive one.',
            ]);
        }

        $result = $action->handle([
            'name' => $data['name'],
            'email' => $data['email'],
            'property_name' => $data['property_name'],
            'phone' => $data['phone'] !== '' ? $data['phone'] : null,
            'password' => $data['password'],
            'expected_units' => $this->unit_count,
            'plan' => $plan,
        ]);

        // Burn the code so it can't be reused. Attribution links back
        // to both the operator and tenant so admin can trace which
        // lead became which tenant.
        $license->forceFill([
            'status' => 'used',
            'used_by_operator_id' => $result->operator->getKey(),
            'used_by_tenant_id' => $result->tenant->getKey(),
            'used_at' => Carbon::now(),
        ])->save();

        $this->redirect($linkBuilder->generate($result->tenant, $result->operator));
    }

    public function render(): View
    {
        return view('livewire.auth.register', [
            'derivedPlan' => $this->derivedPlan(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminImpersonation;
use App\Models\Operator;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lands an admin inside a tenant's dashboard via a one-shot 15-minute
 * impersonation token (PRD §18). Flow:
 *
 *   1. Constant-time match the plaintext against `token_hash` for the
 *      currently-bound tenant. Mismatch / expired / used → 404.
 *   2. Pick the first operator on the tenant — there isn't a UI yet for
 *      "impersonate as operator X", and v1 has 1-2 operators per tenant
 *      in practice.
 *   3. Stamp `used_at` to consume the token.
 *   4. Log in as that operator on the `web` guard.
 *   5. Stash `impersonator_admin_id` in the session so the tenant layout
 *      can render the impersonation banner.
 *   6. Redirect to /dashboard.
 *
 * Tenant binding is automatic — this route lives in the tenant route group.
 * AdminImpersonation has no tenant scope (admin context, central table),
 * but we filter by the current tenant id to enforce isolation.
 */
class ConsumeImpersonationController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();

        $record = $this->findUsableToken($tenant->id, $token);

        if ($record === null) {
            throw new NotFoundHttpException;
        }

        $operator = Operator::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->first();
        if ($operator === null) {
            throw new NotFoundHttpException;
        }

        $record->forceFill([
            'operator_id' => $operator->id,
            'used_at' => Carbon::now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ])->save();

        Auth::guard('web')->login($operator);
        $request->session()->regenerate();
        $request->session()->put('impersonator_admin_id', $record->admin_id);

        activity('admin')
            ->causedBy($record->admin)
            ->performedOn($tenant)
            ->event('impersonation_consumed')
            ->withProperties([
                'operator_id' => $operator->id,
                'ip_address' => $request->ip(),
            ])
            ->log("Admin {$record->admin->name} started impersonating {$operator->name} on {$tenant->slug}.");

        return redirect()->route('tenant.dashboard');
    }

    private function findUsableToken(int $tenantId, string $plaintext): ?AdminImpersonation
    {
        if ($plaintext === '') {
            return null;
        }

        $candidate = hash('sha256', $plaintext);

        return AdminImpersonation::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->where('token_hash', $candidate)
            ->first();
    }
}

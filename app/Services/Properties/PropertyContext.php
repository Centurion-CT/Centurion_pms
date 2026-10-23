<?php

declare(strict_types=1);

namespace App\Services\Properties;

use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Resolves + persists the operator's "currently working in" branch.
 * Every dashboard surface that shows branch-specific data (rooms,
 * bookings, calendar, etc.) reads through here so the switcher in
 * the sidebar changes what they see without a page reload dance.
 *
 * Resolution order for `current()`:
 *   1. `operator.current_property_id` — if set, still belongs to the
 *      operator's tenant, AND the operator has access to it (see
 *      {@see \App\Models\Operator::hasAccessToBranch()})
 *   2. First accessible property for the tenant, ordered by id (so
 *      pre-multi-property tenants' "main" branch wins)
 *   3. null (tenant has no property OR the operator was scoped to
 *      branches that no longer exist)
 *
 * `switchTo($property)` writes to the operator row so the choice
 * survives logout — using session would drop the context every
 * time the sidekiq wakes them back up in the morning.
 */
class PropertyContext
{
    /**
     * Request attribute holding a per-request {operator, property}
     * binding. Set by bearer-token surfaces (the host app's
     * `host.role` middleware) where there is no `web` session user,
     * so the dashboard services that call `current()` with no
     * arguments (DashboardSnapshot, FinanceSnapshot, ReportSnapshot)
     * resolve the token operator's branch. The web dashboard never
     * sets it, so web resolution is unchanged.
     */
    public const REQUEST_BINDING = 'keverna.property_context';

    public static function bindForRequest(Request $request, Operator $operator, Property $property): void
    {
        $request->attributes->set(self::REQUEST_BINDING, [
            'operator' => $operator,
            'property' => $property,
        ]);
    }

    public function current(?Operator $operator = null, ?Tenant $tenant = null): ?Property
    {
        $tenant ??= Tenant::current();
        if ($tenant === null) {
            return null;
        }

        // Request-bound branch wins when the caller didn't name an
        // operator: the binding middleware already verified access.
        if ($operator === null) {
            $binding = $this->requestBinding();
            if ($binding !== null && (int) $binding['property']->tenant_id === (int) $tenant->getKey()) {
                return $binding['property'];
            }
        }

        $operator ??= $this->defaultOperator();

        if ($operator instanceof Operator && $operator->current_property_id !== null) {
            $picked = Property::query()
                ->where('id', $operator->current_property_id)
                ->where('tenant_id', $tenant->getKey())
                ->first();
            if ($picked !== null && $this->operatorCanAccess($operator, $picked)) {
                return $picked;
            }
        }

        return $this->firstAccessible($operator, $tenant);
    }

    /**
     * Persist the operator's branch choice. Silently no-ops for
     * anonymous callers so guest-side reads through `current()` never
     * accidentally write to a null operator. Also refuses to persist
     * a branch the operator doesn't have access to — the switcher's
     * UI already hides those, but a POST bypass would otherwise
     * quietly land an operator on a branch they can't see.
     */
    public function switchTo(Property $property, ?Operator $operator = null): bool
    {
        $operator ??= $this->defaultOperator();
        if (! $operator instanceof Operator) {
            return false;
        }
        if (! $this->operatorCanAccess($operator, $property)) {
            return false;
        }
        $operator->forceFill(['current_property_id' => $property->getKey()])->save();
        return true;
    }

    /**
     * Every property THIS OPERATOR can work in. When the operator has
     * an empty operator_properties pivot (or is a Manager), returns
     * every property under the tenant — same shape as the pre-access-
     * scoping behavior. When restricted, returns only the pivot set.
     *
     * @return \Illuminate\Support\Collection<int, Property>
     */
    public function all(?Tenant $tenant = null, ?Operator $operator = null): \Illuminate\Support\Collection
    {
        $tenant ??= Tenant::current();
        if ($tenant === null) {
            return collect();
        }

        $operator ??= $this->defaultOperator();
        $query = Property::query()
            ->where('tenant_id', $tenant->getKey())
            ->orderBy('id');

        if ($operator instanceof Operator) {
            $ids = $operator->accessibleBranchIds();
            if (! empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        return $query->get();
    }

    /**
     * Everything the operator's tenant OWNS, regardless of access —
     * used by admin surfaces (branches management page, staff
     * permissions) that need the full list to render checkboxes.
     * Never call this for guest-facing or operator-facing listings.
     *
     * @return \Illuminate\Support\Collection<int, Property>
     */
    public function allForTenant(?Tenant $tenant = null): \Illuminate\Support\Collection
    {
        $tenant ??= Tenant::current();
        if ($tenant === null) {
            return collect();
        }

        return Property::query()
            ->where('tenant_id', $tenant->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * The operator to resolve for when the caller passed none: the
     * request-bound (bearer) operator if a binding exists, otherwise
     * the web session operator (the original behaviour).
     */
    private function defaultOperator(): mixed
    {
        $binding = $this->requestBinding();
        if ($binding !== null) {
            return $binding['operator'];
        }

        return auth('web')->user();
    }

    /**
     * @return array{operator: Operator, property: Property}|null
     */
    private function requestBinding(): ?array
    {
        if (! app()->bound('request')) {
            return null;
        }
        $binding = app('request')->attributes->get(self::REQUEST_BINDING);

        return is_array($binding)
            && ($binding['operator'] ?? null) instanceof Operator
            && ($binding['property'] ?? null) instanceof Property
            ? $binding
            : null;
    }

    private function operatorCanAccess(Operator $operator, Property $property): bool
    {
        return $operator->hasAccessToBranch($property);
    }

    private function firstAccessible(?Operator $operator, Tenant $tenant): ?Property
    {
        $query = Property::query()
            ->where('tenant_id', $tenant->getKey())
            ->orderBy('id');

        if ($operator instanceof Operator) {
            $ids = $operator->accessibleBranchIds();
            if (! empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        return $query->first();
    }
}

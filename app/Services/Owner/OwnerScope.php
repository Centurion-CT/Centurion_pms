<?php

declare(strict_types=1);

namespace App\Services\Owner;

use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Properties\PropertyContext;
use Illuminate\Support\Collection;

/**
 * Which branches an owner figure covers (docs/host-app/PHASE-4.md):
 *
 *  - branch: the branch `host.role` bound for this request.
 *  - all:    every branch the operator can access in this tenant,
 *            the Portfolio page's rule ({@see PropertyContext::all()}).
 *
 * `accessible` is always the operator's full branch list (the owner
 * home lists every branch whatever the scope).
 */
final class OwnerScope
{
    /**
     * @param  Collection<int, Property>  $properties  branches in scope
     * @param  Collection<int, Property>  $accessible  every accessible branch
     */
    private function __construct(
        public readonly string $scope,
        public readonly Property $current,
        public readonly Collection $properties,
        public readonly Collection $accessible,
    ) {}

    public static function resolve(string $scope, Operator $operator, Property $current, ?Tenant $tenant = null): self
    {
        $accessible = app(PropertyContext::class)->all($tenant ?? Tenant::current(), $operator)->values();
        if (! $accessible->contains(fn (Property $p) => (int) $p->id === (int) $current->id)) {
            $accessible = $accessible->push($current)->sortBy('id')->values();
        }

        $scope = $scope === 'all' ? 'all' : 'branch';
        $properties = $scope === 'all' ? $accessible : collect([$current]);

        return new self($scope, $current, $properties->values(), $accessible);
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values($this->properties->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function count(): int
    {
        return $this->properties->count();
    }

    public function isAll(): bool
    {
        return $this->scope === 'all';
    }

    /** "Ikoyi" or "All branches" for headings and share text. */
    public function label(): string
    {
        return $this->isAll() && $this->count() > 1 ? 'All branches' : (string) $this->current->name;
    }
}

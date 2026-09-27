<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\OperatorRole;
use App\Models\Operator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates routes that necessarily involve setting a price — creating a
 * product (initial sell price + cost) or an outlet (tax rate) — to
 * roles for which {@see OperatorRole::canManagePrices()} is true.
 *
 * Sits alongside `role:inventory` on the two "create" routes so an
 * InventoryClerk who edits inventory day-to-day still can't set new
 * pricing via a deep-link. Edit paths stay open — the Livewire form
 * strips price / cost / tax fields on save for non-managers.
 */
class EnsurePriceManager
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Operator|null $operator */
        $operator = $request->user('web');
        if ($operator === null) {
            return redirect()->route('login');
        }

        $role = $operator->role ?? OperatorRole::Manager;
        if (! $role->canManagePrices()) {
            session()->flash('error', 'Only managers can create products or outlets. Ask a manager to add it, then you can maintain it.');

            return redirect()->route($role->homeRoute());
        }

        return $next($request);
    }
}

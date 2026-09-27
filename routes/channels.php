<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
 * Private per-tenant channel for dashboard search-cache push
 * events. Only operators logged into the tenant subdomain whose
 * tenant_id matches the channel's {tenantId} pass. Payload is the
 * same shape /api/dashboard/search-cache already returns them via
 * pull, so no extra authz surface to reason about.
 */
Broadcast::channel(
    'tenant.{tenantId}.bookings',
    function (\App\Models\Operator $operator, int $tenantId): bool {
        return (int) $operator->tenant_id === (int) $tenantId;
    },
    ['guards' => ['web']],
);

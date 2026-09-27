<?php

declare(strict_types=1);

namespace App\Livewire\Subscription;

use App\Actions\Subscriptions\InitiateSubscriptionUpgrade;
use App\Enums\SubscriptionPlan;
use App\Models\Operator;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tenant-side subscription management (PRD §3 / §4.2). For v1 launch the only
 * action is "Upgrade to Annual", which kicks off a Paystack checkout via
 * InitiateSubscriptionUpgrade. The webhook handles the actual activation.
 */
#[Layout('layouts.tenant-auth')]
class Manage extends Component
{
    public function upgrade(InitiateSubscriptionUpgrade $action): void
    {
        /** @var Operator $operator */
        $operator = auth()->guard('web')->user();

        $authorizationUrl = $action->handle($operator, SubscriptionPlan::Annual);

        $this->redirect($authorizationUrl);
    }

    public function render(): View
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();

        return view('livewire.subscription.manage', [
            'tenant' => $tenant,
            'currentSubscription' => $tenant->currentSubscription(),
            'annualPriceNgn' => SubscriptionPlan::Annual->amountNgn(),
        ]);
    }
}

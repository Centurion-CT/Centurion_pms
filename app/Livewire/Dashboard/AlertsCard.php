<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Operator;
use App\Services\Alerts\OperatorAlerts;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "To whom it concerns" card on the dashboard home. Shows the same
 * store-request + low-stock signals the sidebar bell chimes on, but
 * rendered as a visible, always-there card so an operator who lands
 * on /dashboard sees them without opening the bell dropdown.
 *
 * Polls the same 60s cadence as the bell (slightly slower to keep
 * the dashboard rendering cheap). Delegates all "who sees what" to
 * {@see OperatorAlerts}, so the bell + card share one source of
 * truth for role gating.
 */
class AlertsCard extends Component
{
    public function render(OperatorAlerts $alerts): View
    {
        $operator = auth('web')->user();
        if (! $operator instanceof Operator) {
            return view('livewire.dashboard.alerts-card', [
                'counts' => ['store_request' => 0, 'low_stock' => 0],
                'items'  => collect(),
            ]);
        }

        return view('livewire.dashboard.alerts-card', [
            'counts' => $alerts->counts($operator),
            'items'  => $alerts->items($operator)->take(5),
        ]);
    }
}

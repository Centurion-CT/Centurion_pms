<?php

declare(strict_types=1);

namespace App\Livewire\Admin\AiSpend;

use App\Models\AiExtraction;
use App\Services\ContentImport\ImportBudget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Super-admin AI-spend audit dashboard.
 *
 * Shows this month's total spend against the configured soft + hard
 * caps, per-provider breakdown, and the latest 50 extraction rows so
 * the admin can spot anomalies (a runaway page burning tokens, a
 * failing provider that's still billed). All read-only.
 */
#[Layout('admin.layout')]
class Index extends Component
{
    public function render(ImportBudget $budget): View
    {
        $monthStart = Carbon::now()->startOfMonth();
        $spendKobo = $budget->monthlySpendKobo();
        $softCap = $budget->softCap();
        $hardCap = $budget->hardCap();

        $byProvider = AiExtraction::query()
            ->where('ran_at', '>=', $monthStart)
            ->selectRaw('provider, sum(cost_kobo) as cost_kobo, count(*) as calls, sum(case when succeeded then 0 else 1 end) as errors')
            ->groupBy('provider')
            ->get();

        $recent = AiExtraction::query()
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('livewire.admin.ai-spend.index', [
            'spendKobo'  => $spendKobo,
            'softCap'    => $softCap,
            'hardCap'    => $hardCap,
            'byProvider' => $byProvider,
            'recent'     => $recent,
        ]);
    }
}

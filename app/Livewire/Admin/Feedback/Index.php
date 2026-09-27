<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Feedback;

use App\Models\FeedbackSubmission;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Super-admin feedback inbox — one row per operator answer,
 * filterable by module + rating, plus a rollup showing average
 * rating and response count per module across the platform.
 */
#[Layout('admin.layout')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'module', except: '')]
    public string $moduleFilter = '';

    #[Url(as: 'rating', except: '')]
    public string $ratingFilter = '';

    public function updatedModuleFilter(): void { $this->resetPage(); }
    public function updatedRatingFilter(): void { $this->resetPage(); }

    public function render(): View
    {
        // Admin routes run without a bound tenant; Operator carries
        // BelongsToTenant which applies TenantScope globally, so an
        // unscoped eager-load throws NoCurrentTenant. Bypass the
        // scope on both relations — this is admin-side aggregate
        // reporting, cross-tenant by design.
        $query = FeedbackSubmission::query()
            ->with([
                'operator' => fn ($q) => $q
                    ->withoutGlobalScope(\App\Multitenancy\TenantScope::class)
                    ->select(['id', 'name', 'email', 'tenant_id']),
                'tenant:id,name',
            ])
            ->orderByDesc('created_at');

        if ($this->moduleFilter !== '') $query->where('module', $this->moduleFilter);
        if ($this->ratingFilter !== '') $query->where('rating', (int) $this->ratingFilter);

        $submissions = $query->paginate(30);

        // Per-module rollup: avg rating + response count. Useful for
        // spotting the module operators are most frustrated with.
        $rollup = FeedbackSubmission::query()
            ->selectRaw('module, COUNT(*) as count, ROUND(AVG(rating), 2) as avg_rating')
            ->groupBy('module')
            ->orderByDesc('count')
            ->get();

        $modules = FeedbackSubmission::query()
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->all();

        return view('livewire.admin.feedback.index', [
            'submissions' => $submissions,
            'rollup'      => $rollup,
            'modules'     => $modules,
            'total'       => FeedbackSubmission::query()->count(),
        ]);
    }
}

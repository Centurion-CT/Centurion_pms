<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Feedback;

use App\Models\FeedbackPromptDismissal;
use App\Models\FeedbackSubmission;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Floating "2-minute feedback" prompt included in the tenant-auth
 * layout. Server-side render() decides whether to show at all
 * (module-scoped, one prompt per operator per module, 30-day
 * cooldown after skip, permanent after submit). Client-side JS
 * decides WHEN inside the page (dwell + scroll heuristic).
 *
 * The 'module' key is inferred from the current route's prefix so
 * an operator answering "how's Rates?" while on /dashboard/rates
 * records feedback tagged 'rates'. Global (route-less) landings
 * fall back to 'dashboard'.
 */
class Prompt extends Component
{
    public bool $open = false;

    #[Validate('required|integer|min:1|max:5')]
    public int $rating = 0;

    #[Validate('nullable|string|max:2000')]
    public string $body = '';

    public bool $submitted = false;

    /**
     * Frozen at mount() so subsequent Livewire updates (which run
     * under the livewire.* route) don't reclassify the module as
     * "livewire". The value here is the module the operator was
     * ORIGINALLY looking at when the component first booted.
     */
    public string $moduleKey = 'dashboard';

    public string $routeKey = '';

    public function mount(): void
    {
        $this->syncFromCurrentRoute((string) (request()->route()?->getName() ?: ''));
    }

    /**
     * Refresh the module bucket to a new route name — fired from the
     * layout's `livewire:navigated` JS listener when the operator
     * hops between pages via wire:navigate. The Prompt instance
     * persists across page morphs (Livewire 3 keeps layout children
     * alive) so mount() only runs once at initial page load; without
     * this hook the moduleKey would stay stuck on the first-visited
     * module forever + skips on Page A would suppress the prompt on
     * every subsequent page.
     */
    public function refreshForRoute(string $routeName): void
    {
        $this->syncFromCurrentRoute($routeName);
        // Reset local widget state so a fresh module starts clean —
        // any open card / typed rating / body from the previous page
        // gets wiped so the operator isn't handed a partial form.
        $this->open = false;
        $this->submitted = false;
        $this->rating = 0;
        $this->body = '';
    }

    private function syncFromCurrentRoute(string $routeName): void
    {
        $parts = explode('.', $routeName);
        $mod = $parts[1] ?? 'dashboard';
        $this->moduleKey = mb_substr($mod, 0, 40) ?: 'dashboard';
        $this->routeKey = mb_substr($routeName, 0, 120);
    }

    public function submit(): void
    {
        $this->validate();

        $operator = auth('web')->user();
        $tenant = Tenant::current();
        if (! $operator instanceof Operator || $tenant === null) return;

        FeedbackSubmission::query()->create([
            'tenant_id'   => $tenant->getKey(),
            'operator_id' => $operator->getKey(),
            'module'      => $this->moduleKey,
            'route'       => $this->routeKey,
            'rating'      => $this->rating,
            'body'        => trim($this->body) !== '' ? trim($this->body) : null,
        ]);

        FeedbackPromptDismissal::query()->updateOrCreate(
            ['operator_id' => $operator->getKey(), 'module' => $this->moduleKey],
            ['action' => 'submitted', 'dismissed_at' => Carbon::now()],
        );

        $this->submitted = true;
        // Auto-close after a beat so the "thanks" flash lingers, then
        // the widget disappears on its own without a click.
        $this->dispatch('feedback-submitted');
    }

    public function skip(): void
    {
        $operator = auth('web')->user();
        if (! $operator instanceof Operator) return;

        FeedbackPromptDismissal::query()->updateOrCreate(
            ['operator_id' => $operator->getKey(), 'module' => $this->moduleKey],
            ['action' => 'skipped', 'dismissed_at' => Carbon::now()],
        );
        $this->open = false;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    /**
     * Whether we should render at all — driven by (a) is there an
     * authenticated operator, (b) has this operator already been
     * asked about this module recently. render() short-circuits to
     * an empty div otherwise so the layout still gets a Livewire
     * root but the widget stays invisible.
     */
    private function shouldRender(): bool
    {
        $operator = auth('web')->user();
        if (! $operator instanceof Operator) return false;

        $existing = FeedbackPromptDismissal::query()
            ->where('operator_id', $operator->getKey())
            ->where('module', $this->moduleKey)
            ->first();

        return $existing === null || $existing->isDueForReprompt();
    }

    public function render(): View
    {
        return view('livewire.dashboard.feedback.prompt', [
            'shouldRender' => $this->shouldRender(),
            'module'       => $this->moduleKey,
        ]);
    }
}

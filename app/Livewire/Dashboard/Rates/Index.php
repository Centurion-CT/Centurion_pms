<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Rates;

use App\Models\Property;
use App\Models\RateRule;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /dashboard/rates — dynamic pricing for the /stays online booking
 * surface. Front-desk bookings are unaffected: RateRule rows are read
 * ONLY by the marketplace-side RateResolver.
 *
 * Layout is a 12-month calendar per category (mirrors the reservations
 * calendar pattern) with existing rules rendered as coloured bands.
 * A dedicated form panel below lets the operator create a rule against
 * the currently-selected date range + category, with condition toggles
 * for device / referrer / time-of-day.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    // Which category the calendar + form is currently focused on.
    // Empty string = property-wide rules ("applies to every category").
    #[Url(as: 'category')]
    public string $categoryId = '';

    // Calendar view anchor (month picker). Rules whose date range
    // intersects [monthStart, monthStart+11 months) render on the grid.
    #[Url(as: 'anchor')]
    public string $anchor = '';

    // Form state — populated when the operator clicks "New rate" or an
    // existing rule row in the sidebar.
    public ?int $editingRuleId = null;
    public string $label = '';
    public ?int $rate_ngn = null;
    public string $starts_on = '';
    public string $ends_on = '';
    /** @var list<string> */
    public array $devices = [];
    /** @var list<string> */
    public array $referrers = [];
    public string $time_start = '';
    public string $time_end = '';
    public int $priority = 0;
    public bool $is_active = true;
    public bool $showForm = false;

    public function mount(): void
    {
        if ($this->anchor === '') {
            $this->anchor = Carbon::today()->startOfMonth()->toDateString();
        }
    }

    public function shiftAnchor(int $months): void
    {
        $this->anchor = Carbon::parse($this->anchor ?: Carbon::today())
            ->startOfMonth()
            ->addMonths($months)
            ->toDateString();
    }

    public function openNewRule(string $startsOn = '', string $endsOn = ''): void
    {
        $this->reset(['editingRuleId', 'label', 'rate_ngn', 'starts_on',
            'ends_on', 'devices', 'referrers', 'time_start', 'time_end',
            'priority', 'is_active']);
        $this->is_active = true;
        $this->starts_on = $startsOn !== '' ? $startsOn : Carbon::today()->toDateString();
        $this->ends_on = $endsOn !== '' ? $endsOn : $this->starts_on;
        $this->showForm = true;
    }

    public function editRule(int $ruleId): void
    {
        $rule = RateRule::query()->findOrFail($ruleId);
        $this->editingRuleId = $rule->id;
        $this->categoryId = $rule->room_category_id ? (string) $rule->room_category_id : '';
        $this->label = (string) ($rule->label ?? '');
        $this->rate_ngn = (int) $rule->rate_ngn;
        $this->starts_on = $rule->starts_on->toDateString();
        $this->ends_on = $rule->ends_on->toDateString();
        $this->devices = (array) ($rule->devices ?? []);
        $this->referrers = (array) ($rule->referrers ?? []);
        $this->time_start = $rule->time_start ?? '';
        $this->time_end = $rule->time_end ?? '';
        $this->priority = (int) $rule->priority;
        $this->is_active = (bool) $rule->is_active;
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->reset(['editingRuleId', 'label', 'rate_ngn', 'starts_on',
            'ends_on', 'devices', 'referrers', 'time_start', 'time_end',
            'priority', 'is_active', 'showForm']);
    }

    public function save(): void
    {
        // NEW rules can't back-date — the /stays online surface serves
        // the guest the rate that's active NOW, so a rule that starts
        // last month has no effect on current pricing anyway. Blocking
        // it keeps the calendar's "past = read-only" affordance honest.
        // Edits are exempt: an in-flight promo may span into today
        // from a start-date that's now in the past.
        $backdateRule = $this->editingRuleId === null
            ? 'required|date|after_or_equal:today'
            : 'required|date';
        $this->validate([
            'rate_ngn'   => 'required|integer|min:1',
            'starts_on'  => $backdateRule,
            'ends_on'    => 'required|date|after_or_equal:starts_on',
            'label'      => 'nullable|string|max:120',
            'time_start' => 'nullable|date_format:H:i',
            'time_end'   => 'nullable|date_format:H:i',
            'priority'   => 'integer|min:0|max:1000',
        ]);

        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $property = $tenant->property;
        if ($property === null) return;

        $categoryId = $this->categoryId !== '' ? (int) $this->categoryId : null;

        $attrs = [
            'tenant_id'         => $tenant->id,
            'property_id'       => $property->id,
            'room_category_id'  => $categoryId,
            'label'             => trim($this->label) !== '' ? trim($this->label) : null,
            'rate_ngn'          => $this->rate_ngn,
            'starts_on'         => $this->starts_on,
            'ends_on'           => $this->ends_on,
            'devices'           => $this->pruneList($this->devices),
            'referrers'         => $this->pruneList($this->referrers),
            'time_start'        => $this->time_start !== '' ? $this->time_start.':00' : null,
            'time_end'          => $this->time_end !== '' ? $this->time_end.':00' : null,
            'priority'          => $this->priority,
            'is_active'         => $this->is_active,
        ];

        if ($this->editingRuleId !== null) {
            RateRule::query()->where('id', $this->editingRuleId)->update($attrs);
        } else {
            $attrs['created_by_operator_id'] = \Illuminate\Support\Facades\Auth::guard('web')->id();
            RateRule::query()->create($attrs);
        }

        $this->cancelForm();
        $this->dispatch('notify', title: 'Rate saved', message: 'Your /stays page reflects the change.', kind: 'ok');
    }

    public function toggleActive(int $ruleId): void
    {
        $rule = RateRule::query()->findOrFail($ruleId);
        $rule->is_active = ! $rule->is_active;
        $rule->save();
    }

    public function deleteRule(int $ruleId): void
    {
        RateRule::query()->where('id', $ruleId)->delete();
        if ($this->editingRuleId === $ruleId) $this->cancelForm();
        $this->dispatch('notify', title: 'Rate removed', kind: 'ok');
    }

    /**
     * Filter out empty/"any" entries so the resolver reads the JSON
     * column as "no constraint" instead of matching only literal "any".
     *
     * @param  array<int, string>  $items
     * @return list<string>|null
     */
    private function pruneList(array $items): ?array
    {
        $clean = array_values(array_unique(array_filter(
            array_map('trim', $items),
            fn ($v) => $v !== '' && $v !== 'any',
        )));
        return $clean === [] ? null : $clean;
    }

    public function render(): View
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $property = $tenant->property;
        if ($property === null) throw new NotFoundHttpException;

        $categories = RoomCategory::query()
            ->where('property_id', $property->id)
            ->orderBy('base_rate')
            ->get();

        $anchor = Carbon::parse($this->anchor ?: Carbon::today())->startOfMonth();
        $rangeStart = $anchor->copy();
        $rangeEnd = $anchor->copy()->addMonths(12);

        // Rules intersecting the visible 12-month window, plus a
        // client-side categoryId filter so the calendar renders a
        // focused view.
        $rules = RateRule::query()
            ->where('property_id', $property->id)
            ->where(function ($q): void {
                $q->where(function ($qq): void {
                    if ($this->categoryId !== '') {
                        $qq->where('room_category_id', (int) $this->categoryId)
                            ->orWhereNull('room_category_id');
                    }
                });
            })
            ->whereDate('ends_on', '>=', $rangeStart->toDateString())
            ->whereDate('starts_on', '<=', $rangeEnd->toDateString())
            ->orderByDesc('priority')
            ->orderBy('starts_on')
            ->get();

        return view('livewire.dashboard.rates.index', [
            'property'    => $property,
            'categories'  => $categories,
            'rules'       => $rules,
            // Named to avoid colliding with the public string $anchor
            // property (Livewire merges component publics into the view
            // scope, so a bare $anchor would be the raw URL-synced
            // date string, not the Carbon we resolved below).
            'anchorMonth' => $anchor,
        ]);
    }
}

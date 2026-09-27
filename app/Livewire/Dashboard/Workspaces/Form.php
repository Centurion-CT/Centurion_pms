<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Workspaces;

use App\Enums\WorkspaceType;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    #[Locked]
    public ?int $workspaceId = null;

    public string $name = '';

    public string $type = 'hot_desk';

    public int $capacity = 1;

    public string $description = '';

    public ?int $hourly_rate_naira = null;

    public ?int $daily_rate_naira = null;

    public bool $is_active = true;

    public function mount(?Workspace $workspace = null): void
    {
        if ($workspace !== null && $workspace->exists) {
            $this->workspaceId = $workspace->id;
            $this->name = $workspace->name;
            $this->type = $workspace->type->value;
            $this->capacity = $workspace->capacity;
            $this->description = (string) $workspace->description;
            $this->hourly_rate_naira = $workspace->hourly_rate_naira;
            $this->daily_rate_naira = $workspace->daily_rate_naira;
            $this->is_active = $workspace->is_active;
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:120'],
            'type'              => ['required', Rule::in(array_map(fn ($c) => $c->value, WorkspaceType::cases()))],
            'capacity'          => ['required', 'integer', 'min:1', 'max:1000'],
            'description'       => ['nullable', 'string', 'max:5000'],
            'hourly_rate_naira' => ['nullable', 'integer', 'min:0'],
            'daily_rate_naira'  => ['nullable', 'integer', 'min:0'],
            'is_active'         => ['boolean'],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $property = $tenant?->property;
        if ($property === null) {
            throw new NotFoundHttpException('Finish onboarding first.');
        }

        $workspace = $this->workspaceId !== null
            ? Workspace::query()->findOrFail($this->workspaceId)
            : new Workspace;

        if (! $workspace->exists) {
            $workspace->property_id = $property->id;
            $workspace->slug = $this->uniqueSlug($data['name'], $tenant->id);
        }

        $workspace->fill($data)->save();

        session()->flash('status', $this->workspaceId ? 'Workspace updated.' : 'Workspace added.');
        $this->redirectRoute('tenant.workspaces.index', navigate: true);
    }

    private function uniqueSlug(string $name, int $tenantId): string
    {
        $base = Str::slug($name) ?: 'space';
        $slug = $base;
        $i = 1;
        while (Workspace::query()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    public function render(): View
    {
        return view('livewire.dashboard.workspaces.form', [
            'types'  => WorkspaceType::cases(),
            'isEdit' => $this->workspaceId !== null,
        ]);
    }
}

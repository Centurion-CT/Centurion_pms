<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\EmailTemplates;

use App\Enums\EmailTemplateKey;
use App\Models\EmailTemplate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    public function render(): View
    {
        $overrides = EmailTemplate::query()
            ->get()
            ->keyBy(fn (EmailTemplate $t) => $t->key->value);

        $rows = collect(EmailTemplateKey::cases())->map(function (EmailTemplateKey $key) use ($overrides) {
            $override = $overrides->get($key->value);

            return (object) [
                'key' => $key,
                'is_customised' => $override !== null,
                'is_active' => $override?->active ?? true,
                'updated_at' => $override?->updated_at,
            ];
        });

        return view('livewire.dashboard.email-templates.index', [
            'rows' => $rows,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\EmailTemplates;

use App\Enums\EmailTemplateKey;
use App\Models\EmailTemplate;
use App\Models\Tenant;
use App\Services\Email\EmailTemplateRenderer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Per-template editor. Subject + body are live-bound so the preview
 * pane updates on every keystroke; "Reset to default" wipes any
 * override row and restores the defaults from {@see EmailTemplateKey}.
 *
 * Active toggle lets the operator temporarily disable a customisation
 * without losing their edits, when off, the renderer falls back to
 * the default.
 */
#[Layout('layouts.tenant-auth')]
class Edit extends Component
{
    #[Locked]
    public string $key = '';

    public string $subject = '';

    public string $body = '';

    public bool $active = true;

    public function mount(string $key): void
    {
        $resolved = EmailTemplateKey::tryFrom($key);
        if ($resolved === null) {
            abort(404);
        }
        $this->key = $resolved->value;

        $tenantId = (int) Tenant::current()?->id;
        $override = EmailTemplate::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $resolved->value)
            ->first();

        $this->subject = $override?->subject ?? $resolved->defaultSubject();
        $this->body = $override?->body ?? $resolved->defaultBody();
        $this->active = (bool) ($override?->active ?? true);
    }

    public function keyEnum(): EmailTemplateKey
    {
        return EmailTemplateKey::from($this->key);
    }

    public function save(): void
    {
        $data = $this->validate([
            'subject' => ['required', 'string', 'max:240'],
            'body' => ['required', 'string', 'max:20000'],
            'active' => ['boolean'],
        ]);

        $tenant = Tenant::current();
        if ($tenant === null) {
            return;
        }

        EmailTemplate::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'key' => $this->key,
            ],
            [
                'subject' => $data['subject'],
                'body' => $data['body'],
                'active' => (bool) $data['active'],
                'updated_by_operator_id' => auth('web')->id(),
            ],
        );

        $this->dispatch('notify', title: 'Done', message: 'Template saved. New sends use this version immediately.', kind: 'ok');
    }

    public function resetToDefault(): void
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return;
        }

        EmailTemplate::query()
            ->where('tenant_id', $tenant->id)
            ->where('key', $this->key)
            ->delete();

        $key = $this->keyEnum();
        $this->subject = $key->defaultSubject();
        $this->body = $key->defaultBody();
        $this->active = true;

        $this->dispatch('notify', title: 'Done', message: 'Template reset to default.', kind: 'ok');
    }

    public function render(EmailTemplateRenderer $renderer): View
    {
        $key = $this->keyEnum();
        $property = Tenant::current()?->property;

        $context = $renderer->sampleContext(
            propertyName: (string) ($property?->name ?? 'Your Property'),
            propertyAddress: (string) ($property?->address ?? ''),
        );

        // Build a preview using the LIVE form state (not the persisted
        // row) so the operator sees what they're editing.
        $previewSubject = $this->substitute($this->subject, $context);
        $previewBody = $this->substitute($this->body, $context);

        return view('livewire.dashboard.email-templates.edit', [
            'keyEnum' => $key,
            'variables' => EmailTemplateKey::availableVariables(),
            'previewSubject' => $previewSubject,
            'previewBody' => $previewBody,
            'defaultSubject' => $key->defaultSubject(),
            'defaultBody' => $key->defaultBody(),
            'isDefault' => $this->subject === $key->defaultSubject() && $this->body === $key->defaultBody(),
        ]);
    }

    /**
     * Defers to {@see EmailTemplateRenderer::applyTokens} so preview
     * output matches what a real send would produce (including blank-
     * line cleanup for empty optional tokens).
     *
     * @param  array<string, string>  $variables
     */
    private function substitute(string $template, array $variables): string
    {
        return app(EmailTemplateRenderer::class)->applyTokens($template, $variables);
    }
}

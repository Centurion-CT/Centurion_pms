<?php

declare(strict_types=1);

/**
 * A debounced `wire:model.live` holds the value back from a quick submit:
 * type, click straight away, and the old value is what gets saved. Fields
 * a form submits use plain `wire:model`, with a debounced $refresh for
 * anything that reacts as you type.
 */
it('sends what was just typed with the submit', function (string $view, string $field): void {
    $blade = file_get_contents(resource_path("views/livewire/{$view}.blade.php"));

    expect($blade)->toContain("wire:model=\"{$field}\"")
        ->not->toMatch('/wire:model\.live\.debounce\.\d+ms="'.$field.'"/');
})->with([
    'invoice deposit' => ['dashboard/invoices/create', 'depositApplied'],
    'property video' => ['dashboard/settings/property-form', 'videoUrl'],
    'storage bucket' => ['dashboard/integrations/dokwe-storage', 'bucket'],
    'sign-up name' => ['platform/sign-up', 'name'],
    'sign-up address' => ['platform/sign-up', 'slug'],
    'sign-up city' => ['platform/sign-up', 'city'],
]);

<?php

declare(strict_types=1);

use App\Models\Tenant;

/**
 * /stays: Keverna's marketplace search, now the door to the business's
 * own apartments. Old links, and the sitemap's entry, keep working and
 * keep any stay they carried.
 */
function onStaysBusiness(string $slug): void
{
    Tenant::factory()->create(['slug' => $slug]);
    onBusinessHost($slug);
}

it('sends /stays to the apartments on the business address', function (): void {
    onStaysBusiness('stays-door');

    $this->get('/stays')
        ->assertStatus(301)
        ->assertRedirect(route('site.results'));
});

it('keeps the stay an old /stays search carried', function (): void {
    onStaysBusiness('stays-dates');

    $this->get('/stays?q=lagos&from=2026-12-10&to=2026-12-12&adults=2&children=0&budget=90000')
        ->assertStatus(301)
        ->assertRedirect(route('site.results', ['in' => '2026-12-10', 'out' => '2026-12-12', 'adults' => '2']));
});

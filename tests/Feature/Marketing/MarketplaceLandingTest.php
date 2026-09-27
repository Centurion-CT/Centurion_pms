<?php

declare(strict_types=1);

/*
 * Pins the Airbnb-style marketplace landing contract:
 *   - / renders the catalog landing with centered category tabs +
 *     compact search pill + row-of-cards content sections.
 *   - /for-hosts serves the preserved operator marketing.
 *
 * The /search endpoint that this file used to cover is now a 301 to
 * /stays; the real-data results page is covered end-to-end in
 * StaysIndexTest, so we only pin the landing + hosts pages here.
 *
 * Structural (testid + key copy) rather than visual — free to tweak
 * spacing / colours without breaking tests.
 */

it('renders the marketplace landing at /', function (): void {
    $response = $this->get('http://keverna.test/');

    $response->assertOk();
    $response->assertSeeHtml('data-testid="search-pill"');
    // Expanding-fields pill: three segments + submit + three popovers.
    $response->assertSeeHtml('data-testid="seg-where"');
    $response->assertSeeHtml('data-testid="seg-when"');
    $response->assertSeeHtml('data-testid="seg-who"');
    $response->assertSeeHtml('data-testid="search-submit"');
    $response->assertSeeHtml('data-testid="pop-where"');
    $response->assertSeeHtml('data-testid="pop-when"');
    $response->assertSeeHtml('data-testid="pop-who"');
    // Category tabs still shipped.
    $response->assertSeeHtml('data-testid="cat-all"');
    $response->assertSeeHtml('data-testid="cat-stay"');
    $response->assertSeeHtml('data-testid="cat-workspace"');
    $response->assertSeeHtml('data-testid="cat-rent"');
    // Content rows.
    $response->assertSeeHtml('data-testid="row-popular-stays"');
    $response->assertSeeHtml('data-testid="row-hotels"');
    $response->assertSeeHtml('data-testid="row-workspaces"');
    $response->assertSeeHtml('data-testid="row-rentals"');
    // Owner path.
    $response->assertSeeHtml('data-testid="become-host"');
    $response->assertSeeHtml('data-testid="host-cta"');
});

it('serves the preserved operator marketing at /for-hosts', function (): void {
    $response = $this->get('http://keverna.test/for-hosts');

    $response->assertOk();
    $response->assertSee('Every unit,');
    $response->assertSee('one page.');
});

it('landing links point at /for-hosts for the owner path', function (): void {
    $response = $this->get('http://keverna.test/');

    $response->assertOk();
    $response->assertSeeHtml('href="'.route('for-hosts').'"');
});

it('/search 301-redirects to /stays', function (): void {
    // Legacy URL contract preserved for the welcome-v2 landing pill
    // + prior SEO / bookmarks; the results page proper lives at /stays.
    $response = $this->get('http://keverna.test/search?type=stay&q=Lagos');
    $response->assertStatus(301);
    expect((string) $response->headers->get('Location'))->toContain('/stays');
});

it('renders the editorial hero above the content rows', function (): void {
    $response = $this->get('http://keverna.test/');

    $response->assertOk();
    $response->assertSeeHtml('data-testid="landing-hero"');
    $response->assertSee('for any duration.');
});

it('search pill rehydrates from ?q/from/to/guests URL params', function (): void {
    // The Alpine component reads these seeds via x-data, so they end
    // up inline in the initial state and can be re-composed on submit.
    $response = $this->get('http://keverna.test/?q=Lagos&from=2026-09-10&to=2026-09-14&guests=2');

    $response->assertOk();
    $response->assertSee('Lagos');
    $response->assertSee('2026-09-10');
    $response->assertSee('2026-09-14');
});

it('landing nav includes both the categories row and a scrolled summary pill', function (): void {
    // CSS/Alpine handles the swap on scroll; test just pins that both
    // elements ship in the markup so the transition has something to
    // animate between.
    $response = $this->get('http://keverna.test/');

    $response->assertOk();
    $response->assertSeeHtml('data-testid="landing-nav"');
    $response->assertSeeHtml('data-testid="scrolled-summary"');
    $response->assertSeeHtml('data-testid="cat-all"');
});


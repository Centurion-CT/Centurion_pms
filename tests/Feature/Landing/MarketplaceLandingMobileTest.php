<?php

declare(strict_types=1);

/**
 * Smoke test for the v2 marketplace landing after the mobile pass —
 * proves the route still renders + the key structural markers are
 * present. The visual regressions this pass targets are best caught
 * in a browser at 375px / 768px / 1440px, not here.
 */

it('renders the marketplace v2 landing with all key sections', function (): void {
    $response = $this->get(route('home.v2'));

    $response->assertOk();
    $response->assertSee('mp-page', false);              // scoped CSS class survives
    $response->assertSee('data-testid="landing-nav"', false);
    $response->assertSee('data-testid="landing-hero"', false);
    $response->assertSee('data-testid="search-pill"', false);
    $response->assertSee('data-testid="host-band"', false);
    $response->assertSee('data-testid="footer"', false);
});

it('exposes the four category tabs used by mobile horizontal-scroll nav', function (): void {
    $response = $this->get(route('home.v2'));

    foreach (['cat-all', 'cat-stay', 'cat-workspace', 'cat-rent'] as $testid) {
        $response->assertSee('data-testid="'.$testid.'"', false);
    }
});

it('sets the viewport meta so mobile browsers scale correctly', function (): void {
    $response = $this->get(route('home.v2'));

    // width=device-width so the mobile CSS breakpoints activate at
    // real pixel sizes; without it iOS Safari renders at 980px logical.
    $response->assertSee('width=device-width', false);
});

it('ships an overflow-x lock so a wide element cannot cause horizontal scroll', function (): void {
    $response = $this->get(route('home.v2'));

    // The mobile-refinements block hard-caps body width so a stray
    // wide grid or image cannot make the page scroll sideways.
    // Uses `clip` not `hidden` so the .mp-nav's sticky positioning
    // still works on desktop / tablet (overflow: hidden on html/
    // body would create a new scroll container + break sticky).
    $response->assertSee('overflow-x: clip', false);
});

it('ships the mobile search chip + bottom-sheet flow', function (): void {
    $response = $this->get(route('home.v2'));

    // The chip sits inline in the nav before the language button;
    // tapping it flips a shared Alpine flag that adds the
    // is-mobile-sheet class on the full search shell.
    $response->assertSee('data-testid="mobile-search-chip"', false);
    $response->assertSee('data-testid="mobile-search-scrim"', false);
    // The multi-field search shell is present in every viewport —
    // desktop shows it inline, mobile shows it as a bottom sheet
    // when the chip fires the flag.
    $response->assertSee('mp-search-shell', false);
    $response->assertSee('data-testid="search-pill"', false);
});

it('ships the mobile drawer with a hamburger toggle + category items', function (): void {
    $response = $this->get(route('home.v2'));

    // Hamburger toggle + drawer + scrim all render (visibility is CSS).
    $response->assertSee('data-testid="mobile-menu-toggle"', false);
    $response->assertSee('data-testid="mobile-menu-drawer"', false);
    $response->assertSee('data-testid="mobile-menu-scrim"', false);
    $response->assertSee('data-testid="mobile-menu-close"', false);

    // Category items inside the drawer are duplicated from the desktop
    // .mp-cats and wired to the same Alpine `tab` state (lifted x-data).
    foreach (['mobile-cat-all', 'mobile-cat-stay', 'mobile-cat-workspace', 'mobile-cat-rent'] as $testid) {
        $response->assertSee('data-testid="'.$testid.'"', false);
    }
    $response->assertSee('data-testid="mobile-become-host"', false);
});

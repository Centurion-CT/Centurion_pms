<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysBookCheckout;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Cancel flow on /stays/{slug}/book. Escape hatch back to the
 * property detail page they were browsing, with the dates + guest
 * count preserved in the URL so the property page opens on the
 * same search context.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'cancel-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true,
        'name' => 'Cancel Test Property',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    Tenant::forgetCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders a cancel link in the top row of the checkout', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->assertSee('data-testid="checkout-cancel"', false)
        ->assertSee('Cancel')
        ->assertSee($this->property->name);   // "back to <name>" caption
});

it('cancel link points at the property detail page + carries the guest\'s dates + guest count', function (): void {
    $from = Carbon::today()->addDays(5)->toDateString();
    $to = Carbon::today()->addDays(7)->toDateString();

    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', $from)
        ->set('checkout', $to)
        ->set('adults', 3)
        ->set('children', 1);

    $html = $c->html();
    // Locate the cancel anchor + extract its href.
    preg_match('/<a[^>]*data-testid="checkout-cancel"[^>]*href="([^"]+)"/', $html, $m);
    $href = $m[1] ?? '';
    if ($href === '') {
        // Attributes might be in a different order (href before testid).
        preg_match('/<a[^>]*href="([^"]+)"[^>]*data-testid="checkout-cancel"/', $html, $m);
        $href = $m[1] ?? '';
    }

    // Points at /stays/{slug}
    expect($href)->toContain('/stays/'.$this->property->public_slug);
    // Carries the guest's context forward.
    expect($href)->toContain('from='.$from);
    expect($href)->toContain('to='.$to);
    expect($href)->toContain('adults=3');
    expect($href)->toContain('children=1');
});

it('omits date + guest params from the cancel link when the guest cleared them', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', '')
        ->set('checkout', '')
        ->set('adults', 1)
        ->set('children', 0);

    $html = $c->html();
    preg_match('/<a[^>]*data-testid="checkout-cancel"[^>]*href="([^"]+)"/', $html, $m);
    $href = $m[1] ?? '';
    if ($href === '') {
        preg_match('/<a[^>]*href="([^"]+)"[^>]*data-testid="checkout-cancel"/', $html, $m);
        $href = $m[1] ?? '';
    }

    // Bare slug URL - no query params for from/to/children (0 or blank).
    // Adults defaults to 1 which is a real value + shouldn't leak.
    expect($href)->toBe(route('marketplace.stays.show', ['slug' => $this->property->public_slug]).'?adults=1');
});

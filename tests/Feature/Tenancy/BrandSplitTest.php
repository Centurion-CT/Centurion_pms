<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Settings\Business;
use App\Mail\Bookings\BookingConfirmedMail;
use App\Mail\Platform\SignUpCodeMail;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Support\MailBranding;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * "Centurion" is the software; each business has its own name, title,
 * logo and contact details (docs/MULTITENANCY.md, step 6).
 */

beforeEach(function (): void {
    config()->set('mail.default', 'array');
    config()->set('mail.from.address', 'noreply@keverna.test');
    $this->tenant = Tenant::factory()->create(['slug' => 'sunrise', 'name' => 'Sunrise Court', 'email' => 'desk@sunrise.ng']);
    $this->tenant->makeCurrent();
    $this->branch = Property::factory()->live()->for($this->tenant)->create(['name' => 'Wuse 2', 'city' => 'Abuja']);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
});

afterEach(fn () => Tenant::forgetCurrent());

it("lets a manager set the business's name, booking site title, phone and email", function (): void {
    Livewire::actingAs($this->manager, 'web')->test(Business::class)
        ->set('name', 'Sunrise Court Apartments')->set('siteTitle', 'Sunrise Court · Abuja serviced apartments')
        ->set('phone', '0803 111 2222')->set('email', 'Hello@Sunrise.ng')
        ->call('save')->assertHasNoErrors();

    $this->tenant->refresh();
    expect($this->tenant->name)->toBe('Sunrise Court Apartments')
        ->and($this->tenant->siteTitle())->toBe('Sunrise Court · Abuja serviced apartments')
        ->and($this->tenant->phone)->toBe('0803 111 2222')
        ->and($this->tenant->email)->toBe('hello@sunrise.ng');
});

it('keeps business settings to managers', function (): void {
    $desk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk]);

    $this->actingAs($desk, 'web')->get('http://sunrise.keverna.test/settings/business')->assertRedirect();
    $this->actingAs($this->manager, 'web')->get('http://sunrise.keverna.test/settings/business')->assertOk()->assertSee('Your business');
});

it('falls back to the business name when no site title is set', function (): void {
    expect($this->tenant->siteTitle())->toBe('Sunrise Court');

    $this->tenant->forceFill(['site_title' => '  '])->save();
    expect($this->tenant->refresh()->siteTitle())->toBe('Sunrise Court');
});

it("sends a business's emails under its name, and the platform's under Centurion", function (): void {
    $senders = [];
    Event::listen(MessageSent::class, function (MessageSent $e) use (&$senders): void {
        $senders[] = $e->message->getFrom()[0]->getName();
    });
    $room = Room::factory()->for($this->tenant)->for($this->branch)->create();
    $booking = Booking::factory()->for($this->tenant)->for($room)->create(['guest_email' => 'ada@example.ng']);

    Mail::to('ada@example.ng')->send(new BookingConfirmedMail($booking));
    Tenant::forgetCurrent();
    Mail::to('new@example.ng')->send(new SignUpCodeMail(name: 'Ada', code: '123456', businessName: 'New Place'));

    expect($senders)->toBe(['Sunrise Court', config('brand.name')]);
});

it('brands emails sent with no business as Centurion', function (): void {
    Tenant::forgetCurrent();

    expect(MailBranding::current())->toMatchArray(['name' => config('brand.name'), 'color' => MailBranding::DEFAULT_COLOR]);
});

it("describes the business's own booking site in llms.txt", function (): void {
    Tenant::forgetCurrent();
    $this->tenant->forceFill(['phone' => '0803 111 2222'])->save();
    RoomCategory::factory()->for($this->tenant)->for($this->branch)->create();

    $this->get('http://sunrise.keverna.test/llms.txt')
        ->assertOk()
        ->assertSee('# Sunrise Court')
        ->assertSee('Wuse 2')
        ->assertSee('0803 111 2222')
        ->assertDontSee('Keverna')
        ->assertDontSee('FregateLab');
});

it('hides the support WhatsApp button until a number is set', function (): void {
    Tenant::forgetCurrent();
    config()->set('keverna.support_whatsapp', '');
    $this->get('http://sunrise.keverna.test/login')->assertOk()->assertDontSee('wa.me', false);

    config()->set('keverna.support_whatsapp', '+234 803 000 0000');
    $this->get('http://sunrise.keverna.test/login')->assertOk()->assertSee('wa.me/2348030000000', false);
});

it('sends old /stays links to the building on the booking site', function (): void {
    $this->branch->forceFill(['is_listed_publicly' => true, 'public_slug' => 'sunrise-wuse'])->save();
    Room::factory()->for($this->tenant)->for($this->branch)->create();
    Tenant::forgetCurrent();

    $this->get('http://sunrise.keverna.test/stays/sunrise-wuse?checkin=2026-12-01&checkout=2026-12-03')
        ->assertStatus(301)
        ->assertRedirectContains('/apartments?')
        ->assertRedirectContains('where%5B0%5D='.$this->branch->slug)
        ->assertRedirectContains('in=2026-12-01');
});

it("shows the business's own legal pages, with no Keverna, and no /ndpr", function (string $path): void {
    Tenant::forgetCurrent();

    $this->get("http://sunrise.keverna.test{$path}")
        ->assertOk()
        ->assertSee('Sunrise Court')
        ->assertSee('desk@sunrise.ng')
        ->assertDontSee('Keverna')
        ->assertDontSee('FregateLab');
})->with(['/terms', '/privacy', '/cookies']);

it('no longer serves /ndpr', function (): void {
    Tenant::forgetCurrent();

    $this->get('http://sunrise.keverna.test/ndpr')->assertNotFound();
});

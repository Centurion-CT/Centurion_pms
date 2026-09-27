<?php

declare(strict_types=1);

use App\Http\Middleware\CaptureFirstTouchReferrer;
use App\Models\Property;
use App\Models\RateRule;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Marketplace\RateResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;

function invokeCapture(Request $request)
{
    Cookie::getQueuedCookies() && Cookie::unqueue('kv_ref');
    return app(CaptureFirstTouchReferrer::class)->handle($request, fn () => response('ok'));
}

it('queues a kv_ref cookie when the referer is external', function (): void {
    $request = Request::create('http://citistay.keverna.test/branches/main', 'GET');
    $request->headers->set('Referer', 'https://www.google.com/search?q=citistay+abuja');

    invokeCapture($request);
    $queued = Cookie::queued('kv_ref');

    expect($queued)->not->toBeNull();
    expect($queued->getValue())->toBe('google');
});

it('does not queue a cookie when the referer is same-host (intra-site)', function (): void {
    $request = Request::create('http://citistay.keverna.test/branches/main', 'GET');
    $request->headers->set('Referer', 'http://citistay.keverna.test/');

    invokeCapture($request);
    expect(Cookie::queued('kv_ref'))->toBeNull();
});

it('does not overwrite an existing kv_ref cookie', function (): void {
    $request = Request::create('http://citistay.keverna.test/branches/main', 'GET');
    $request->headers->set('Referer', 'https://www.google.com/');
    $request->cookies->set('kv_ref', 'instagram');

    invokeCapture($request);
    expect(Cookie::queued('kv_ref'))->toBeNull();
});

it('applies a google-only rule when the kv_ref cookie says google (even if Referer is direct)', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $category = RoomCategory::factory()->for($tenant)->for($property)->create(['base_rate' => 30000]);

    RateRule::query()->create([
        'tenant_id'        => $tenant->id,
        'property_id'      => $property->id,
        'room_category_id' => $category->id,
        'rate_ngn'         => 22000,
        'starts_on'        => Carbon::today()->subDay(),
        'ends_on'          => Carbon::today()->addDay(),
        'referrers'        => ['google'],
        'is_active'        => true,
    ]);

    $resolver = app(RateResolver::class);

    // Same-host Referer (branch-chooser hop) + cookie carrying google.
    $request = Request::create('http://citistay.keverna.test/branches/main', 'GET');
    $request->headers->set('Referer', 'http://citistay.keverna.test/');
    $request->cookies->set('kv_ref', 'google');

    $out = $resolver->resolve($category, $request);
    expect($out['rate'])->toBe(22000);
    expect($out['rule'])->not->toBeNull();

    Tenant::forgetCurrent();
});

<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

it('round-trips Paystack secret keys through encryption', function (): void {
    $tenant = Tenant::factory()->create([
        'paystack_secret_key' => 'sk_live_supersecret_value',
    ]);

    // Reloaded via the model: plaintext.
    $fresh = Tenant::query()->find($tenant->id);
    expect($fresh->paystack_secret_key)->toBe('sk_live_supersecret_value');

    // Direct DB read: ciphertext, NOT the plaintext.
    $raw = DB::table('tenants')->where('id', $tenant->id)->value('paystack_secret_key');
    expect($raw)->not->toBe('sk_live_supersecret_value');
    expect($raw)->not->toBeNull();
});

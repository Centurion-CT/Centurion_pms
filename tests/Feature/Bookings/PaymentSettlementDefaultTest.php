<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Bookings\ManualCreate;
use App\Livewire\Dashboard\Home;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->operator = Operator::factory()->for($this->tenant)->create();
    Auth::guard('web')->login($this->operator);
});

afterEach(fn () => Tenant::forgetCurrent());

it('ManualCreate defaults payment_settlement to pending (no phantom payment)', function (): void {
    Livewire\Livewire::test(ManualCreate::class)
        ->assertSet('payment_settlement', 'pending');
});

it('Home quick-reserve form defaults payment_settlement to pending', function (): void {
    Livewire\Livewire::test(Home::class)
        ->assertSet('payment_settlement', 'pending');
});

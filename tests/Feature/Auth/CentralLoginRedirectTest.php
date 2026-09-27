<?php

declare(strict_types=1);

use App\Models\IdentityAccount;
use Illuminate\Support\Facades\Auth;

it('bounces an already-authenticated identity user from /login to /choose (HTTP)', function (): void {
    $identity = IdentityAccount::query()->create([
        'name'     => 'Smoke Person',
        'email'    => 'smoke+'.uniqid().'@keverna.test',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
    ]);
    Auth::guard('identity')->login($identity);

    $this->get('http://'.config('keverna.central_domain').'/login')
        ->assertRedirect(route('central.choose'));
});

it('CentralLogin.mount() redirects when identity is already logged in (component-level)', function (): void {
    $identity = IdentityAccount::query()->create([
        'name'     => 'Smoke Person',
        'email'    => 'smoke+'.uniqid().'@keverna.test',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
    ]);
    Auth::guard('identity')->login($identity);

    Livewire\Livewire::test(\App\Livewire\Central\Login::class)
        ->assertRedirect(route('central.choose'));
});

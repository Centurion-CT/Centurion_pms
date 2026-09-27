<?php

declare(strict_types=1);

use App\Enums\CustomerLifecycle;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Customers\WalletService;
use App\Services\Reports\FinanceSnapshot;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'kpi-deposits']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('exposes depositsOnFile + fundedWallets in the Finance KPI payload', function (): void {
    $c1 = Customer::create(['name' => 'C1', 'email' => 'c1@x.test', 'lifecycle' => CustomerLifecycle::Active]);
    $c2 = Customer::create(['name' => 'C2', 'email' => 'c2@x.test', 'lifecycle' => CustomerLifecycle::Active]);
    $emptied = Customer::create(['name' => 'CE', 'email' => 'ce@x.test', 'lifecycle' => CustomerLifecycle::Active]);

    $svc = app(WalletService::class);
    $svc->topup($c1, 40_000, 'seed');
    $svc->topup($c2, 12_500, 'seed');
    $svc->topup($emptied, 5_000, 'seed');
    $svc->spend($emptied, 5_000, 'spent');

    $today = Carbon::today();
    $kpis = app(FinanceSnapshot::class)->kpis($today->copy()->subDays(29), $today);

    expect($kpis['depositsOnFile'])->toBe(52_500);
    expect($kpis['fundedWallets'])->toBe(2);
});

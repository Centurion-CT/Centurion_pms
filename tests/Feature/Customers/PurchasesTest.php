<?php

declare(strict_types=1);

use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Enums\OutletType;
use App\Livewire\Dashboard\Customers\Show;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'cust-purch']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Bar',
        'type' => OutletType::Bar,
    ]);
    $this->product = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'outlet_id' => $this->outlet->id,
        'name' => 'Guinness Small',
        'price' => 1500,
        'cost' => 800,
        'track_stock' => true,
        'quantity_on_hand' => 40,
    ]);
    $this->customer = Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Amaka Regular',
        'phone' => '08011112222',
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/** Helper — completes a paid or comp sale for a specific customer. */
function _ring(
    Outlet $outlet,
    Operator $operator,
    Product $product,
    int $qty,
    ?int $customerId,
    bool $comp = false,
    ?string $reason = null,
): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $outlet,
        operator: $operator,
        cart: [['product_id' => $product->id, 'quantity' => $qty]],
        customerId: $customerId,
        saleIsComplimentary: $comp,
        complimentaryReason: $reason,
    );
    app(CompletePosSale::class)->handle(sale: $draft, operator: $operator, method: 'cash');
}

it('rolls up paid + comp purchases for a customer and streams the CSV export', function (): void {
    _ring($this->outlet, $this->operator, $this->product, 2, $this->customer->id);           // 3000 paid
    _ring($this->outlet, $this->operator, $this->product, 1, $this->customer->id);           // 1500 paid
    _ring($this->outlet, $this->operator, $this->product, 3, $this->customer->id, comp: true, reason: 'VIP welcome drink'); // 4500 comp
    _ring($this->outlet, $this->operator, $this->product, 5, customerId: null);              // unattributed — should be excluded

    $this->actingAs($this->operator, 'web');

    $response = $this->get(route('tenant.customers.purchases.export', $this->customer));
    $response->assertOk();
    $body = $response->streamedContent();

    // Header block carries the customer identity.
    expect($body)->toContain('Amaka Regular');
    expect($body)->toContain('08011112222');

    // Paid rows appear with tender.
    expect($body)->toContain('Guinness Small');
    expect($body)->toContain('cash');

    // Comp row carries the reason + "complimentary" tender.
    expect($body)->toContain('VIP welcome drink');
    expect($body)->toContain('complimentary');

    // Unattributed sale (customer_id = null) must NOT bleed into the CSV.
    // Row count check: 3 sales × 1 line each = 3 data rows + header rows.
    $dataRows = substr_count($body, "\nGuinness Small,");
    // fputcsv doesn't lead with a comma; count occurrences of the product
    // name at the start of a CSV field instead.
    expect(substr_count($body, 'Guinness Small'))->toBe(3);
});

it('renders the customer profile page with the Purchases section', function (): void {
    _ring($this->outlet, $this->operator, $this->product, 2, $this->customer->id);
    _ring($this->outlet, $this->operator, $this->product, 1, $this->customer->id, comp: true, reason: 'welcome drink');

    $this->actingAs($this->operator, 'web');

    Livewire::test(Show::class, ['customer' => $this->customer])
        ->assertSee('Purchases')
        ->assertSee('Guinness Small')
        ->assertSee('Complimentary')
        ->assertSee('Download CSV');
});

it('scopes purchases to the current property so out-of-branch customers 404', function (): void {
    // Second property + a customer bound to it. The current authed
    // operator has no membership on the other property so the export
    // should refuse.
    $otherProperty = Property::factory()->for($this->tenant)->create();
    $stranger = Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $otherProperty->id,
        'name' => 'Not Mine',
        'phone' => '07033334444',
    ]);
    _ring($this->outlet, $this->operator, $this->product, 1, $stranger->id);

    $this->actingAs($this->operator, 'web');
    $this->get(route('tenant.customers.purchases.export', $stranger))->assertNotFound();
});

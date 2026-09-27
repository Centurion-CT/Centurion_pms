<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreateInvoice;
use App\Enums\BookingStatus;
use App\Enums\InvoiceLineType;
use App\Enums\OperatorRole;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'invoice-pdf']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Ada Guest',
        'guest_email' => 'ada@example.com',
        'booking_status' => BookingStatus::CheckedOut,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders a downloadable PDF for an issued invoice', function (): void {
    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant,
        $this->booking,
        $this->manager,
        [[
            'line_type' => InvoiceLineType::Night->value,
            'description' => '2 nights · Deluxe · King bed',
            'quantity' => 2,
            'unit_price' => 25_000,
        ]],
    );

    $response = $this->actingAs($this->manager, 'web')
        ->get(route('tenant.invoices.pdf', $invoice));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    // Content-Disposition should include the invoice number.
    expect($response->headers->get('content-disposition'))->toContain($invoice->number);
    // Sanity — the bytes actually start with the PDF magic.
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('renders a PDF for a Draft invoice using a fallback filename', function (): void {
    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant,
        $this->booking,
        $this->manager,
        [[
            'line_type' => InvoiceLineType::Custom->value,
            'description' => 'Late-checkout fee',
            'quantity' => 1,
            'unit_price' => 5_000,
        ]],
        issue: false,
    );

    $response = $this->actingAs($this->manager, 'web')
        ->get(route('tenant.invoices.pdf', $invoice));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('invoice-draft-'.$invoice->id.'.pdf');
});

it('an unauthenticated request cannot download an invoice', function (): void {
    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant,
        $this->booking,
        $this->manager,
        [[
            'line_type' => InvoiceLineType::Night->value,
            'description' => '1 night',
            'quantity' => 1,
            'unit_price' => 10_000,
        ]],
    );

    $response = $this->get(route('tenant.invoices.pdf', $invoice));

    // Web guard redirects to login — redirect or 302.
    expect($response->status())->toBeIn([302, 401]);
});

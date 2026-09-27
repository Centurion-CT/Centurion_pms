<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreateInvoice;
use App\Actions\Invoicing\RecordCorporatePayment;
use App\Actions\Invoicing\SuggestInvoiceLines;
use App\Enums\CorporateStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Corporate;
use App\Models\CorporatePayment;
use App\Models\CorporatePaymentAllocation;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Corporate AR ledger: incoming payments auto-offset unpaid invoices
 * oldest-first (FIFO). Any leftover parks on the CorporatePayment as
 * unallocated credit for the next invoice.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'corp-ledger']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 50_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->corp = Corporate::query()->create([
        'slug' => 'acme-'.uniqid(),
        'name' => 'Acme Ltd',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test',
        'country' => 'NG',
        'created_by_tenant_id' => $this->tenant->id,
    ]);
    Carbon::setTestNow(Carbon::create(2026, 9, 13, 10, 0, 0));
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

/**
 * Helper: issue an invoice against this corp for the given total.
 */
function issueCorpInvoice(
    Tenant $tenant, Room $room, Corporate $corp, Operator $operator, int $total, int $daysAgo = 0
): \App\Models\Invoice {
    $b = Booking::factory()->for($tenant)->for($room)->create([
        'guest_name' => 'Corp Guest '.uniqid(),
        'total_amount' => $total, 'balance_due' => $total,
        'nights' => 1, 'room_total' => $total,
        'corporate_id' => $corp->id,
    ]);
    $inv = app(CreateInvoice::class)->handle(
        tenant: $tenant,
        booking: $b->fresh(),
        operator: $operator,
        lines: app(SuggestInvoiceLines::class)->handle($b->fresh()),
        notes: null,
        dueAt: null,
        issue: true,
        initialAmountPaid: 0,
    );
    // Backdate issued_at so FIFO tests can control ordering. CreateInvoice
    // takes no issuedAt param — writing it after is safe because
    // RecordCorporatePayment's ordering only reads this column.
    if ($daysAgo > 0) {
        $inv->forceFill(['issued_at' => Carbon::now()->subDays($daysAgo)])->save();
    }
    return $inv->refresh();
}

it('a payment covering the oldest invoice fully clears it', function (): void {
    $oldest = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 100_000, daysAgo: 5);
    $newer = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 80_000, daysAgo: 1);

    app(RecordCorporatePayment::class)->handle(
        corporate: $this->corp, operator: $this->manager,
        amount: 100_000, method: 'transfer',
    );

    expect($oldest->refresh()->status)->toBe(InvoiceStatus::Paid);
    expect((int) $oldest->amount_paid)->toBe(100_000);
    // Newer untouched.
    expect($newer->refresh()->status)->toBe(InvoiceStatus::Issued);
    expect((int) $newer->amount_paid)->toBe(0);
});

it('a payment larger than the oldest invoice splits across both (FIFO)', function (): void {
    $oldest = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 60_000, daysAgo: 5);
    $newer = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 80_000, daysAgo: 1);

    $payment = app(RecordCorporatePayment::class)->handle(
        corporate: $this->corp, operator: $this->manager,
        amount: 100_000, method: 'transfer',
    );

    // Oldest fully cleared, remainder (₦40k) applied to newer.
    // Newer now flips to Partial — some payment landed but the
    // balance isn't cleared yet.
    expect($oldest->refresh()->status)->toBe(InvoiceStatus::Paid);
    expect((int) $oldest->amount_paid)->toBe(60_000);
    expect($newer->refresh()->status)->toBe(InvoiceStatus::Partial);
    expect((int) $newer->amount_paid)->toBe(40_000);

    // Two allocation rows on the payment.
    $allocs = CorporatePaymentAllocation::query()
        ->where('corporate_payment_id', $payment->id)
        ->get();
    expect($allocs)->toHaveCount(2);
    expect($allocs->sum('amount_naira'))->toBe(100_000);
});

it('a partial payment flips the invoice to Partial with amount_paid recorded', function (): void {
    // Behavior since InvoiceStatus::Partial landed: any payment that
    // doesn't clear the full total drops the invoice into Partial, not
    // Issued. Balance stays visible on receivables, Pay button still
    // shows on the row.
    $inv = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 100_000, daysAgo: 2);

    app(RecordCorporatePayment::class)->handle(
        corporate: $this->corp, operator: $this->manager,
        amount: 40_000, method: 'transfer',
    );

    expect($inv->refresh()->status)->toBe(InvoiceStatus::Partial);
    expect((int) $inv->amount_paid)->toBe(40_000);
});

it('a follow-up payment on a Partial invoice keeps it Partial until fully paid', function (): void {
    // First partial → Partial. Second partial that doesn't clear the
    // balance → still Partial. Third that closes the balance → Paid.
    // Locks the transition Partial → Partial → Paid so we can't
    // regress to bouncing back to Issued mid-way.
    $inv = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 100_000, daysAgo: 3);

    app(RecordCorporatePayment::class)->handle(
        corporate: $this->corp, operator: $this->manager,
        amount: 30_000, method: 'transfer',
    );
    expect($inv->refresh()->status)->toBe(InvoiceStatus::Partial);
    expect((int) $inv->amount_paid)->toBe(30_000);

    app(RecordCorporatePayment::class)->handle(
        corporate: $this->corp, operator: $this->manager,
        amount: 25_000, method: 'cash',
    );
    expect($inv->refresh()->status)->toBe(InvoiceStatus::Partial);
    expect((int) $inv->amount_paid)->toBe(55_000);

    app(RecordCorporatePayment::class)->handle(
        corporate: $this->corp, operator: $this->manager,
        amount: 45_000, method: 'transfer',
    );
    expect($inv->refresh()->status)->toBe(InvoiceStatus::Paid);
    expect((int) $inv->amount_paid)->toBe(100_000);
    expect($inv->refresh()->paid_at)->not->toBeNull();
});

it('an overpayment parks unallocated credit on the payment row', function (): void {
    $inv = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 50_000, daysAgo: 2);

    $payment = app(RecordCorporatePayment::class)->handle(
        corporate: $this->corp, operator: $this->manager,
        amount: 80_000, method: 'transfer',
    );

    expect($inv->refresh()->status)->toBe(InvoiceStatus::Paid);
    // 50k allocated, 30k parks as credit.
    $allocated = (int) CorporatePaymentAllocation::query()
        ->where('corporate_payment_id', $payment->id)
        ->sum('amount_naira');
    expect($allocated)->toBe(50_000);
    expect((int) $payment->amount_naira - $allocated)->toBe(30_000);
});

it('recordCorporatePayment refuses ₦0', function (): void {
    expect(fn () => app(RecordCorporatePayment::class)->handle(
        corporate: $this->corp, operator: $this->manager,
        amount: 0, method: 'transfer',
    ))->toThrow(\RuntimeException::class, '₦0');
});

it('Corporate Show surfaces CheckedOut corp stays with balance owed as "Ready to invoice"', function (): void {
    // Regression: after checkout the corp guest's balance was
    // effectively invisible — the pulse widget skipped CheckedOut,
    // and the ledger only listed rows that already had an Invoice.
    // Now the Show exposes uninvoicedStays.
    \App\Models\Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => \App\Enums\BookingStatus::CheckedOut,
        'guest_name' => 'Just Checked Out',
        'total_amount' => 90_000, 'balance_due' => 90_000,
        'nights' => 2, 'room_total' => 90_000,
        'corporate_id' => $this->corp->id,
        'checkin_date' => Carbon::create(2026, 9, 10),
        'checkout_date' => Carbon::create(2026, 9, 12),
    ]);

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Corporates\Show::class, ['corporate' => $this->corp])
        ->assertViewHas('uninvoicedOutstanding', 90_000)
        ->assertSeeHtml('data-testid="uninvoiced-stays"')
        ->assertSee('Just Checked Out')
        ->assertSee('Ready to invoice');
});

it('opening the Record payment drawer prefills Amount with the ledger outstanding', function (): void {
    // Two unpaid Issued invoices — the amount field on the payment
    // drawer should open pre-filled with their combined balance so
    // "pay in full" is one click without re-typing.
    issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 50_000);
    issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 30_000);

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Corporates\Show::class, ['corporate' => $this->corp])
        ->call('openPaymentForm')
        ->assertSet('paymentAmount', 80_000);
});

it('recording a corp payment mirrors it onto the tenant-wide Payments ledger', function (): void {
    // Corp AR receipts must show up on /payments so daily cash
    // reconciles across guest folios + corporate remittances. The
    // mirror row references the CorporatePayment source-of-truth.
    issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 50_000);

    $before = \App\Models\Payment::query()->count();
    $paid = app(\App\Actions\Invoicing\RecordCorporatePayment::class)->handle(
        corporate: $this->corp,
        operator: $this->manager,
        amount: 50_000,
        method: 'transfer',
        reference: 'GTB-9876',
    );

    expect(\App\Models\Payment::query()->count())->toBe($before + 1);
    $mirror = \App\Models\Payment::query()->latest('id')->first();
    expect($mirror->type)->toBe(\App\Enums\PaymentType::CorporateAr);
    expect($mirror->status)->toBe(\App\Enums\PaymentStatus::Succeeded);
    expect((int) $mirror->amount)->toBe(50_000);
    expect((int) $mirror->corporate_id)->toBe($this->corp->id);
    expect((int) $mirror->corporate_payment_id)->toBe($paid->id);
    expect($mirror->booking_id)->toBeNull();
    expect($mirror->method)->toBe('transfer');
    expect($mirror->reference_note)->toBe('GTB-9876');
});

it('Corporate Show ledger renders a chronological statement with running balance', function (): void {
    // Two invoices + one partial payment. Statement newest-first,
    // balance-after column reflects the running walk from oldest.
    Carbon::setTestNow(Carbon::create(2026, 9, 10, 10));
    $olderInv = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 80_000);
    Carbon::setTestNow(Carbon::create(2026, 9, 11, 10));
    $newerInv = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 20_000);
    Carbon::setTestNow(Carbon::create(2026, 9, 12, 10));
    app(\App\Actions\Invoicing\RecordCorporatePayment::class)->handle(
        corporate: $this->corp,
        operator: $this->manager,
        amount: 50_000,
        method: 'transfer',
    );
    Carbon::setTestNow(Carbon::create(2026, 9, 13, 10));

    $test = \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Corporates\Show::class, ['corporate' => $this->corp]);

    // Three statement rows: 2 invoices + 1 payment.
    $test->assertSeeHtml('data-testid="statement-section"');
    $test->assertSeeHtml('data-testid="statement-row-invoice-'.$olderInv->id.'"');
    $test->assertSeeHtml('data-testid="statement-row-invoice-'.$newerInv->id.'"');
    // Current balance after chronological walk: 80k + 20k − 50k = 50k.
    $test->assertSeeHtml('data-testid="statement-current-balance"');
    $test->assertSee('₦50,000');
});

it('paying a specific invoice from the ledger settles that row, not the oldest', function (): void {
    // Two unpaid invoices for the same corp. Operator clicks Pay on
    // the newer one (say a small ₦20k side charge). We expect that
    // ₦20k invoice to close out — not to eat into the older ₦80k
    // which FIFO would otherwise settle first.
    $older = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 80_000, daysAgo: 5);
    $newer = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 20_000, daysAgo: 1);

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Corporates\Show::class, ['corporate' => $this->corp])
        ->call('openPaymentForInvoice', $newer->id)
        ->assertSet('paymentAmount', 20_000)
        ->assertSet('paymentTargetInvoiceId', $newer->id)
        ->call('recordPayment');

    expect($newer->fresh()->status)->toBe(\App\Enums\InvoiceStatus::Paid);
    expect($older->fresh()->status)->toBe(\App\Enums\InvoiceStatus::Issued);
    expect((int) $older->fresh()->amount_paid)->toBe(0);
});

it('overpaying a specific invoice cascades the remainder to FIFO', function (): void {
    // Operator picks the newer ₦20k but pays ₦30k total (say they
    // rounded up on a bank transfer). Newer settles fully; the extra
    // ₦10k lands on the older invoice per FIFO.
    $older = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 80_000, daysAgo: 5);
    $newer = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 20_000, daysAgo: 1);

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Corporates\Show::class, ['corporate' => $this->corp])
        ->call('openPaymentForInvoice', $newer->id)
        ->set('paymentAmount', 30_000)
        ->call('recordPayment');

    expect($newer->fresh()->status)->toBe(\App\Enums\InvoiceStatus::Paid);
    expect($older->fresh()->status)->toBe(\App\Enums\InvoiceStatus::Partial);
    expect((int) $older->fresh()->amount_paid)->toBe(10_000);
});

it('opening Record payment on a paid-up corp leaves Amount empty', function (): void {
    // No unpaid invoices → we don't want to nudge the operator into
    // recording a ₦0 payment; leaving Amount blank prompts them to
    // enter a real number (e.g. an advance credit).
    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Corporates\Show::class, ['corporate' => $this->corp])
        ->call('openPaymentForm')
        ->assertSet('paymentAmount', null);
});

it('a consolidated invoice covering a booking hides it from "Ready to invoice"', function (): void {
    // Regression: consolidated corp invoices have booking_id = NULL
    // and link to their bookings through InvoiceLine.source_id. The
    // uninvoicedStays query only walked booking->invoices() and so
    // kept surfacing an already-billed stay under "Ready to invoice",
    // inviting double-billing.
    $stay = \App\Models\Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => \App\Enums\BookingStatus::CheckedOut,
        'guest_name' => 'Already Billed',
        'total_amount' => 60_000, 'balance_due' => 60_000,
        'nights' => 1, 'room_total' => 60_000,
        'corporate_id' => $this->corp->id,
        'checkin_date' => Carbon::create(2026, 9, 10),
        'checkout_date' => Carbon::create(2026, 9, 11),
    ]);
    app(\App\Actions\Invoicing\CreateConsolidatedCorporateInvoice::class)->handle(
        tenant: $this->tenant,
        corporate: $this->corp,
        operator: $this->manager,
        dateFrom: Carbon::create(2026, 9, 1),
        dateTo: Carbon::create(2026, 9, 30),
        issue: true,
    );

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Corporates\Show::class, ['corporate' => $this->corp])
        ->assertViewHas('uninvoicedOutstanding', 0)
        ->assertDontSeeHtml('data-testid="uninvoiced-stays"');
});

it('consolidated invoice bundles every uninvoiced corp stay in the window', function (): void {
    // Two corp stays, both checked out in September. One prior corp
    // stay is already invoiced separately — should be skipped.
    $bookedOut = fn (Carbon $checkout, int $roomTotal): \App\Models\Booking =>
        \App\Models\Booking::factory()->for($this->tenant)->for($this->room)->create([
            'guest_name' => 'Guest '.uniqid(),
            'nights' => 2,
            'room_total' => $roomTotal,
            'total_amount' => $roomTotal,
            'balance_due' => $roomTotal,
            'corporate_id' => $this->corp->id,
            'checkin_date' => $checkout->copy()->subDays(2),
            'checkout_date' => $checkout,
        ]);
    $b1 = $bookedOut(Carbon::create(2026, 9, 5), 100_000);
    $b2 = $bookedOut(Carbon::create(2026, 9, 20), 150_000);

    // Prior stay that was already invoiced individually.
    $b0 = $bookedOut(Carbon::create(2026, 9, 2), 60_000);
    app(\App\Actions\Invoicing\CreateInvoice::class)->handle(
        tenant: $this->tenant, booking: $b0->fresh(), operator: $this->manager,
        lines: app(\App\Actions\Invoicing\SuggestInvoiceLines::class)->handle($b0->fresh()),
        notes: null, dueAt: null, issue: true, initialAmountPaid: 0,
    );

    $inv = app(\App\Actions\Invoicing\CreateConsolidatedCorporateInvoice::class)->handle(
        tenant: $this->tenant,
        corporate: $this->corp,
        operator: $this->manager,
        dateFrom: Carbon::create(2026, 9, 1),
        dateTo: Carbon::create(2026, 9, 30),
    );

    expect($inv->corporate_id)->toBe($this->corp->id);
    expect($inv->booking_id)->toBeNull();
    expect($inv->guest_name)->toBe($this->corp->name);
    expect((int) $inv->subtotal)->toBe(250_000);   // b1 + b2, b0 already invoiced.

    $roomLines = $inv->lines()->where('line_type', 'night')->get();
    expect($roomLines)->toHaveCount(2);
    expect($roomLines->pluck('source_id')->sort()->values()->all())
        ->toBe(collect([$b1->id, $b2->id])->sort()->values()->all());
});

it('consolidated invoice raises when nothing in the window is uninvoiced', function (): void {
    \App\Models\Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 100_000, 'balance_due' => 100_000, 'nights' => 1, 'room_total' => 100_000,
        'corporate_id' => $this->corp->id,
        'checkin_date' => Carbon::create(2026, 8, 20),
        'checkout_date' => Carbon::create(2026, 8, 21),
    ]);

    expect(fn () => app(\App\Actions\Invoicing\CreateConsolidatedCorporateInvoice::class)->handle(
        tenant: $this->tenant,
        corporate: $this->corp,
        operator: $this->manager,
        dateFrom: Carbon::create(2026, 9, 1),
        dateTo: Carbon::create(2026, 9, 30),
    ))->toThrow(\RuntimeException::class, 'No uninvoiced stays');
});

it('consolidated invoice includes unsettled charges + surfaces corp discount', function (): void {
    $b = \App\Models\Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Corp Guest',
        'nights' => 2, 'room_total' => 100_000,
        'total_amount' => 90_000, 'balance_due' => 90_000,
        'corporate_id' => $this->corp->id,
        'corporate_discount_naira' => 10_000,
        'checkin_date' => Carbon::create(2026, 9, 10),
        'checkout_date' => Carbon::create(2026, 9, 12),
    ]);
    \App\Models\BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $b->id,
        'type' => \App\Enums\BookingChargeType::FoodBeverage->value,
        'description' => 'Rooftop bar tab',
        'amount' => 12_000, 'settled' => false,
    ]);

    $inv = app(\App\Actions\Invoicing\CreateConsolidatedCorporateInvoice::class)->handle(
        tenant: $this->tenant,
        corporate: $this->corp,
        operator: $this->manager,
        dateFrom: Carbon::create(2026, 9, 1),
        dateTo: Carbon::create(2026, 9, 30),
    );

    expect($inv->lines()->count())->toBe(3);
    expect((int) $inv->subtotal)->toBe(102_000);   // 100k + 12k - 10k.
    $discountLine = $inv->lines()->where('line_total', '<', 0)->first();
    expect($discountLine)->not->toBeNull();
    expect((int) $discountLine->line_total)->toBe(-10_000);
});

it('skips Draft + Void invoices (not collectable)', function (): void {
    // A drafted (not yet issued) invoice — status = Draft.
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 100_000, 'balance_due' => 100_000, 'nights' => 1, 'room_total' => 100_000,
        'corporate_id' => $this->corp->id,
    ]);
    $draft = app(CreateInvoice::class)->handle(
        tenant: $this->tenant, booking: $b->fresh(), operator: $this->manager,
        lines: app(SuggestInvoiceLines::class)->handle($b->fresh()),
        notes: null, dueAt: null,
        issue: false,   // Draft
        initialAmountPaid: 0,
    );
    $issued = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 60_000, daysAgo: 3);

    app(RecordCorporatePayment::class)->handle(
        corporate: $this->corp, operator: $this->manager,
        amount: 60_000, method: 'transfer',
    );

    // Draft untouched, issued fully cleared.
    expect($draft->refresh()->status)->toBe(InvoiceStatus::Draft);
    expect((int) $draft->amount_paid)->toBe(0);
    expect($issued->refresh()->status)->toBe(InvoiceStatus::Paid);
});

it('wallet method debits balance + settles invoices oldest-first', function (): void {
    // Fund the wallet with ₦100k, issue ₦80k invoice, pay ₦80k via
    // wallet — balance should drop to ₦20k, invoice should clear.
    $wallets = app(\App\Services\Corporates\WalletService::class);
    $wallets->ensureWallet($this->corp);
    $wallets->topup(corporate: $this->corp, amount: 100_000, causer: $this->manager);
    expect((int) $this->corp->fresh()->wallet->balance_naira)->toBe(100_000);

    $inv = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 80_000);

    app(RecordCorporatePayment::class)->handle(
        corporate: $this->corp, operator: $this->manager,
        amount: 80_000, method: 'wallet',
    );

    expect($inv->refresh()->status)->toBe(InvoiceStatus::Paid);
    expect((int) $this->corp->fresh()->wallet->balance_naira)->toBe(20_000);
});

it('wallet method refuses when the wallet available is short of the amount', function (): void {
    // Wallet has ₦10k, we ask for ₦50k → refuse with a friendly
    // "top up before recording" message. Nothing changes.
    $wallets = app(\App\Services\Corporates\WalletService::class);
    $wallets->ensureWallet($this->corp);
    $wallets->topup(corporate: $this->corp, amount: 10_000, causer: $this->manager);
    $inv = issueCorpInvoice($this->tenant, $this->room, $this->corp, $this->manager, 50_000);

    expect(fn () => app(RecordCorporatePayment::class)->handle(
        corporate: $this->corp, operator: $this->manager,
        amount: 50_000, method: 'wallet',
    ))->toThrow(RuntimeException::class, 'Wallet has');

    // Wallet + invoice untouched.
    expect((int) $this->corp->fresh()->wallet->balance_naira)->toBe(10_000);
    expect($inv->refresh()->status)->toBe(InvoiceStatus::Issued);
});

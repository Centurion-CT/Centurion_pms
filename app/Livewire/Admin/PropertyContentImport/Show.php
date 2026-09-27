<?php

declare(strict_types=1);

namespace App\Livewire\Admin\PropertyContentImport;

use App\Enums\ContentImportStatus;
use App\Jobs\RunPropertyContentImport;
use App\Models\Admin;
use App\Models\Operator;
use App\Models\Property;
use App\Models\PropertyContentImport;
use App\Models\Tenant;
use App\Notifications\PropertyContentReadyNotification;
use App\Services\ContentImport\ImportBudget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Super-admin concierge content import for a single property.
 *
 * Flow:
 *   1. Admin pastes any subset of Google Business / Booking.com /
 *      Airbnb URLs.
 *   2. `dispatchImport()` creates a PropertyContentImport row and
 *      queues RunPropertyContentImport. Row transitions Pending →
 *      Fetching → Ready (or Failed).
 *   3. Once Ready, admin sees the suggested fields side-by-side with
 *      the current property record and ticks which to accept.
 *   4. `approve()` writes accepted fields onto the property, transitions
 *      to Approved, notifies the tenant's operators via email + in-app.
 *
 * 24h re-fetch rate limit — an admin can trigger a fresh import if the
 * previous one is older than 24h; otherwise a compact banner explains
 * the cooldown.
 */
#[Layout('admin.layout')]
class Show extends Component
{
    public int $tenantId;

    public int $propertyId;

    public string $googleUrl = '';

    public string $bookingUrl = '';

    public string $airbnbUrl = '';

    /** @var array<string, bool> */
    public array $accept = [];

    /**
     * Property is tenant-scoped, so we resolve it inside the tenant's
     * context. Route model binding gives us the Tenant; propertyId
     * is looked up after `makeCurrent()`.
     */
    public function mount(Tenant $tenant, int $propertyId): void
    {
        $this->tenantId = $tenant->id;
        $this->propertyId = $propertyId;
    }

    /**
     * Every action + render needs the tenant bound so Property queries
     * see rows in the correct database.
     */
    private function withTenant(callable $fn): mixed
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        return $tenant->execute($fn);
    }

    public function dispatchImport(ImportBudget $budget): void
    {
        try {
            $budget->assertWithinHardCap();
        } catch (RuntimeException $e) {
            $this->addError('googleUrl', $e->getMessage());

            return;
        }

        $this->googleUrl  = trim($this->googleUrl);
        $this->bookingUrl = trim($this->bookingUrl);
        $this->airbnbUrl  = trim($this->airbnbUrl);

        if ($this->googleUrl === '' && $this->bookingUrl === '' && $this->airbnbUrl === '') {
            $this->addError('googleUrl', 'Paste at least one URL to import from.');

            return;
        }

        $property = $this->withTenant(fn () => Property::query()->findOrFail($this->propertyId));

        // 24h re-fetch limit.
        $recent = PropertyContentImport::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [ContentImportStatus::Fetching, ContentImportStatus::Ready])
            ->where('created_at', '>', Carbon::now()->subDay())
            ->exists();
        if ($recent) {
            $this->addError('googleUrl', 'A recent import is still open for this property, resolve it before starting a new one.');

            return;
        }

        $admin = auth('admin')->user();

        $import = PropertyContentImport::query()->create([
            'tenant_id'       => $property->tenant_id,
            'property_id'     => $property->id,
            'google_url'      => $this->googleUrl !== '' ? $this->googleUrl : null,
            'booking_url'     => $this->bookingUrl !== '' ? $this->bookingUrl : null,
            'airbnb_url'      => $this->airbnbUrl !== '' ? $this->airbnbUrl : null,
            'status'          => ContentImportStatus::Pending,
            'run_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
        ]);

        // Run inline (dispatchSync) rather than async — the admin is
        // watching the page, one-shot per property, and the extraction
        // takes ~5-30s. Blocking the Livewire action lets the very
        // next render show the Ready pane.
        //
        // Anything that throws below (bad config, network failure,
        // Spatie tenant guard) writes Failed onto the row so the "Last
        // import failed" banner at the top of the page surfaces it,
        // instead of leaving the row stuck in Pending with no visible
        // signal to the operator.
        try {
            RunPropertyContentImport::dispatchSync($import->id);
        } catch (Throwable $e) {
            $import->refresh();
            if ($import->status === ContentImportStatus::Pending
                || $import->status === ContentImportStatus::Fetching
            ) {
                $import->status = ContentImportStatus::Failed;
                $import->error_message = 'Dispatch failed: '.$e->getMessage();
                $import->save();
            }
            $this->addError('googleUrl', $e->getMessage());

            return;
        }

        $this->googleUrl = '';
        $this->bookingUrl = '';
        $this->airbnbUrl = '';
        session()->flash('status', 'Content fetched. Review below and pick the fields to apply.');
    }

    public function approve(int $importId): void
    {
        $import = PropertyContentImport::query()->findOrFail($importId);
        if ($import->status !== ContentImportStatus::Ready) {
            return;
        }
        if ($import->property_id !== $this->propertyId) {
            return;
        }

        $property = $this->withTenant(fn () => Property::query()->find($import->property_id));
        if ($property === null) {
            $this->addError('accept', 'Property no longer exists.');

            return;
        }

        // Flatten suggestions by field precedence: google > booking > airbnb.
        // Admin's per-field ticks decide what actually lands.
        $suggested = $import->suggested_content ?? [];
        $flat = $this->flattenSuggestions($suggested);
        $approved = [];

        foreach ($flat as $field => $value) {
            if (! ($this->accept[$field] ?? false)) {
                continue;
            }
            if ($this->applyToProperty($property, $field, $value)) {
                $approved[$field] = $value;
            }
        }

        if ($approved === []) {
            $this->addError('accept', 'Tick at least one field to approve.');

            return;
        }

        $this->withTenant(fn () => $property->save());

        $admin = auth('admin')->user();

        $import->approved_content = $approved;
        $import->status = ContentImportStatus::Approved;
        $import->approved_at = now();
        $import->approved_by_admin_id = $admin instanceof Admin ? $admin->id : null;
        $import->save();

        $this->notifyOperators($import);

        session()->flash('status', "Applied ".count($approved)." field(s) to the property and notified the operator.");
    }

    public function reject(int $importId): void
    {
        $import = PropertyContentImport::query()->findOrFail($importId);
        if ($import->status !== ContentImportStatus::Ready) {
            return;
        }
        if ($import->property_id !== $this->propertyId) {
            return;
        }
        $import->status = ContentImportStatus::Rejected;
        $import->save();
        session()->flash('status', 'Rejected.');
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @return array<string, mixed>
     */
    private function flattenSuggestions(array $suggested): array
    {
        $flat = [];
        // Precedence: google (structured, authoritative) beats booking beats airbnb.
        foreach (['google', 'booking', 'airbnb'] as $source) {
            $payload = $suggested[$source] ?? [];
            if (! is_array($payload)) {
                continue;
            }
            foreach ($payload as $field => $value) {
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                if (! array_key_exists($field, $flat)) {
                    $flat[$field] = $value;
                }
            }
        }

        return $flat;
    }

    private function applyToProperty(Property $property, string $field, mixed $value): bool
    {
        return match ($field) {
            'name'          => tap(true, fn () => $property->name = (string) $value),
            'description'   => tap(true, fn () => $property->description = (string) $value),
            'address'       => tap(true, fn () => $property->address = (string) $value),
            'city'          => tap(true, fn () => $property->city = (string) $value),
            'state'         => tap(true, fn () => $property->state = (string) $value),
            'amenities'     => tap(true, fn () => $property->amenities = array_values(array_slice((array) $value, 0, 20))),
            'tagline'       => tap(true, fn () => $property->tagline = (string) $value),
            default         => false,
        };
    }

    private function notifyOperators(PropertyContentImport $import): void
    {
        $tenant = $import->tenant;
        if ($tenant === null) {
            return;
        }
        $tenant->execute(function () use ($import): void {
            Operator::query()
                ->whereNotNull('email')
                ->get()
                ->each(fn (Operator $op) => $op->notify(new PropertyContentReadyNotification($import)));
        });
        $import->notified_operator_at = now();
        $import->save();
    }

    public function render(): View
    {
        $property = $this->withTenant(fn () => Property::query()->findOrFail($this->propertyId));
        $imports = PropertyContentImport::query()
            ->where('property_id', $property->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();
        $active = $imports->firstWhere('status', ContentImportStatus::Ready);
        $suggestedFlat = $active !== null ? $this->flattenSuggestions($active->suggested_content ?? []) : [];

        return view('livewire.admin.property-content-import.show', [
            'property'       => $property,
            'imports'        => $imports,
            'active'         => $active,
            'suggestedFlat'  => $suggestedFlat,
            'budgetOverSoft' => app(ImportBudget::class)->overSoft(),
        ]);
    }
}

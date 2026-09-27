<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ContentImportStatus;
use App\Models\PropertyContentImport;
use App\Services\ContentImport\GooglePlacesExtractor;
use App\Services\ContentImport\ListingContentExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * Runs the concierge content-import pipeline for one PropertyContentImport
 * row, then flips its status to Ready (or Failed) so the super admin's
 * review pane picks it up.
 *
 * Guarantees:
 *   - Every extractor call writes to ai_extractions, whether it
 *     succeeds or not — audit trail is preserved even on error.
 *   - The row transitions Pending → Fetching (before work), then
 *     Ready or Failed (after). Never stuck in Fetching on job death,
 *     Horizon's timeout catches that and this catch-all is defensive.
 *   - Errors don't rethrow; they land on the row's `error_message`
 *     so the admin sees what happened without digging in logs.
 */
/**
 * `NotTenantAware`: dispatched from the super-admin context where no
 * tenant is bound. The job resolves its own tenant from the import row
 * (via PropertyContentImport::tenant_id); Spatie's automatic tenant
 * guard would otherwise refuse to run it.
 */
class RunPropertyContentImport implements ShouldQueue, NotTenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public int $importId) {}

    public function handle(
        GooglePlacesExtractor $places,
        ListingContentExtractor $listing,
    ): void {
        $import = PropertyContentImport::query()->find($this->importId);
        if ($import === null) {
            return;
        }
        if ($import->status !== ContentImportStatus::Pending) {
            return; // already picked up
        }

        $import->status = ContentImportStatus::Fetching;
        $import->save();

        $suggested = [];
        $partialErrors = [];

        if ($import->google_url) {
            try {
                $suggested['google'] = $places->extract($import->google_url, $import->id);
            } catch (Throwable $e) {
                $partialErrors[] = 'Google: '.$e->getMessage();
                Log::warning('content-import google_places failed', [
                    'import_id' => $import->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        foreach (['booking' => $import->booking_url, 'airbnb' => $import->airbnb_url] as $key => $url) {
            if ($url === null) {
                continue;
            }
            try {
                $suggested[$key] = $listing->extract($url, $import->id);
            } catch (Throwable $e) {
                $partialErrors[] = ucfirst($key).': '.$e->getMessage();
                Log::warning('content-import listing failed', [
                    'import_id' => $import->id,
                    'provider'  => $key,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($suggested === []) {
            $import->status = ContentImportStatus::Failed;
            $import->error_message = implode(' · ', $partialErrors) ?: 'No sources returned content.';
            $import->save();

            return;
        }

        $import->suggested_content = $suggested;
        $import->status = ContentImportStatus::Ready;
        $import->fetched_at = now();
        // Partial success — some sources worked, some didn't. Show the
        // admin what failed so they know if a re-fetch might help.
        $import->error_message = $partialErrors === [] ? null : implode(' · ', $partialErrors);
        $import->save();
    }

    public function failed(?Throwable $exception): void
    {
        $import = PropertyContentImport::query()->find($this->importId);
        if ($import === null) {
            return;
        }
        if ($import->status === ContentImportStatus::Fetching) {
            $import->status = ContentImportStatus::Failed;
            $import->error_message = 'Job failed: '.($exception?->getMessage() ?? 'unknown error');
            $import->save();
        }
    }
}

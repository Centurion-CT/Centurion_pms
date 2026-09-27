<?php

declare(strict_types=1);

namespace App\Services\ContentImport;

use App\Models\AiExtraction;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Monthly spend guard for the content-import pipeline.
 *
 * Sums the ai_extractions audit table for the current calendar month
 * on every dispatch. Two ceilings:
 *
 *   Soft cap  visible to super admin as a warning on /admin/ai-spend;
 *             does NOT block new fetches. Signals "we're spending
 *             more than usual — look at it".
 *   Hard cap  refuses new fetches with a clear error the admin sees
 *             on the import screen. Prevents runaway spend if a
 *             pathological page or a bug triggers a flurry of calls.
 *
 * Values in config/services.php `content_import.{soft,hard}_cap_kobo`.
 */
class ImportBudget
{
    public function monthlySpendKobo(): int
    {
        return (int) AiExtraction::query()
            ->where('succeeded', true)
            ->where('ran_at', '>=', Carbon::now()->startOfMonth())
            ->sum('cost_kobo');
    }

    public function softCap(): int
    {
        return (int) config('services.content_import.soft_cap_kobo');
    }

    public function hardCap(): int
    {
        return (int) config('services.content_import.hard_cap_kobo');
    }

    public function overSoft(): bool
    {
        return $this->monthlySpendKobo() >= $this->softCap();
    }

    /**
     * @throws RuntimeException when the monthly hard cap would be crossed.
     */
    public function assertWithinHardCap(): void
    {
        $spent = $this->monthlySpendKobo();
        $hard = $this->hardCap();
        if ($spent >= $hard) {
            throw new RuntimeException(sprintf(
                'AI spend for this month (₦%s) is at or above the hard cap (₦%s). '.
                'Refusing new content-import calls. Raise CONTENT_IMPORT_HARD_CAP_KOBO or wait until next month.',
                number_format($spent / 100),
                number_format($hard / 100),
            ));
        }
    }
}

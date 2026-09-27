<?php

declare(strict_types=1);

namespace App\Console\Commands\Reports;

use App\Actions\Reports\BuildSummaryReport;
use App\Mail\Reports\SummaryReportMail;
use App\Models\SummaryReportSubscriber;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the periodic Summary PDF to every subscriber opted into the
 * given cadence. One PDF per tenant per run — the same bytes are
 * attached to every recipient's mail so we don't re-render for each.
 *
 * Cadences:
 *   weekly  → covers the prior Monday..Sunday
 *   monthly → covers the prior calendar month
 *
 * The scheduler binds the daily fire times (see routes/console.php).
 */
class SendSummaryReports extends Command
{
    protected $signature = 'summary:send-reports {cadence : weekly|monthly}';

    protected $description = 'Email the periodic Summary PDF to every subscribed recipient';

    public function handle(BuildSummaryReport $builder): int
    {
        $cadence = strtolower((string) $this->argument('cadence'));
        if (! in_array($cadence, ['weekly', 'monthly'], true)) {
            $this->components->error("Unknown cadence: {$cadence}. Use 'weekly' or 'monthly'.");

            return self::FAILURE;
        }

        [$from, $to, $periodLabel] = $this->windowFor($cadence);

        $subscriberRows = SummaryReportSubscriber::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where($cadence, true)
            ->get()
            ->groupBy('tenant_id');

        $tenants = 0;
        $recipients = 0;

        foreach ($subscriberRows as $tenantId => $subs) {
            $tenant = Tenant::query()->find($tenantId);
            if (! $tenant) {
                continue;
            }

            $tenant->makeCurrent();
            try {
                $payload = $builder->handle($tenant, $from, $to, $periodLabel);
                $pdfBytes = Pdf::loadView('pdfs.summary-report', $payload)
                    ->setPaper('a4')
                    ->output();
            } finally {
                Tenant::forgetCurrent();
            }

            $filename = 'summary-'.$tenant->slug.'-'.strtolower($periodLabel).'-'.$from->format('Y-m-d').'.pdf';
            $range = $from->format('M j').' – '.$to->format('M j, Y');

            foreach ($subs as $sub) {
                Mail::to($sub->email)->queue(new SummaryReportMail(
                    tenant: $tenant,
                    periodLabel: $periodLabel,
                    periodRange: $range,
                    pdfBytes: $pdfBytes,
                    filename: $filename,
                ));
                $recipients++;
            }
            $tenants++;
        }

        $this->components->info("Summary {$cadence} sent — tenants={$tenants} recipients={$recipients}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function windowFor(string $cadence): array
    {
        $today = Carbon::today();
        if ($cadence === 'weekly') {
            // Prior Mon..Sun. Carbon's startOfWeek() defaults to Monday.
            $lastMon = $today->copy()->subWeek()->startOfWeek();
            $lastSun = $lastMon->copy()->endOfWeek();

            return [$lastMon, $lastSun, 'Weekly'];
        }

        // Prior calendar month.
        $start = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$start, $end, 'Monthly'];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Livewire\Dashboard\Reports\Index as ReportsPage;
use App\Models\Activity;
use App\Models\Operator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports the current Staff-activity tab as a CSV. Query params
 * mirror the Livewire page (from, to, dept, staff, log) so the URL
 * the "Export CSV" link builds serves exactly what the operator is
 * looking at on screen.
 *
 * Rows: one activity_log entry per line, joined with the causing
 * Operator so the CSV carries staff name + role + department without
 * a downstream vlookup.
 */
class StaffActivityCsvExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $from = $this->parseDate($request->query('from'), Carbon::today()->subDays(29));
        $to   = $this->parseDate($request->query('to'), Carbon::today());

        $query = Activity::query()
            ->with(['causer'])
            ->where('causer_type', Operator::class)
            ->whereNotNull('causer_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        if ($log = trim((string) $request->query('log', ''))) {
            $query->where('log_name', $log);
        }

        if ($staff = trim((string) $request->query('staff', ''))) {
            if (ctype_digit($staff)) {
                $query->where('causer_id', (int) $staff);
            }
        } elseif ($dept = trim((string) $request->query('dept', ''))) {
            $depts = ReportsPage::departments();
            if (isset($depts[$dept])) {
                $roleValues = array_map(fn ($r) => $r->value, $depts[$dept]['roles']);
                $opIds = Operator::query()->whereIn('role', $roleValues)->pluck('id')->all();
                $query->whereIn('causer_id', $opIds ?: [0]);
            }
        }

        $filename = 'staff-activity-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        // Stream in chunks so a long window doesn't spike memory. The
        // Payments export loads all rows in one shot; activity_log
        // grows unbounded so chunking is the safer default here.
        return response()->streamDownload(function () use ($query, $from, $to): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) return;

            fputcsv($out, ['Staff activity export']);
            fputcsv($out, ['Range', $from->toDateString().' → '.$to->toDateString()]);
            fputcsv($out, []);
            fputcsv($out, [
                'When',
                'Staff',
                'Role',
                'Department',
                'Domain',
                'Event',
                'Description',
                'Subject type',
                'Subject id',
            ]);

            $query->orderByDesc('id')->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $a) {
                    /** @var \App\Models\Operator|null $causer */
                    $causer = $a->causer;
                    $role = $causer?->role;
                    $deptKey = ReportsPage::departmentForRole($role);
                    $deptLabel = $deptKey ? ReportsPage::departments()[$deptKey]['label'] : '';
                    fputcsv($out, [
                        $a->created_at?->toDateTimeString() ?? '',
                        (string) ($causer?->name ?? ''),
                        (string) ($role?->label() ?? ''),
                        $deptLabel,
                        (string) ($a->log_name ?? ''),
                        (string) ($a->event ?? ''),
                        (string) ($a->description ?? ''),
                        (string) ($a->subject_type ?? ''),
                        (string) ($a->subject_id ?? ''),
                    ]);
                }
                ob_flush();
                flush();
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function parseDate($raw, Carbon $fallback): Carbon
    {
        if (! is_string($raw) || trim($raw) === '') return $fallback;
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}

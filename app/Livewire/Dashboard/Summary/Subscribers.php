<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Summary;

use App\Actions\Reports\BuildSummaryReport;
use App\Mail\Reports\SummaryReportMail;
use App\Models\Operator;
use App\Models\SummaryReportSubscriber;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Subscribers card on the Summary page, manager-only CRUD over the
 * summary_report_subscribers table plus a "Send test now" action that
 * generates and emails a report to a specific address on demand.
 *
 * Nested inside {@see Index}; renders in its own boundary so the
 * outer filter interactions don't force a re-fetch of the subscriber
 * list on every chip click.
 */
class Subscribers extends Component
{
    #[Validate('required|email:rfc|max:190')]
    public string $email = '';

    public bool $weekly = true;

    public bool $monthly = true;

    public string $testEmail = '';

    public string $flash = '';

    public function add(): void
    {
        $this->validate();

        $tenant = Tenant::current();
        if (! $tenant) {
            return;
        }

        // Idempotent: existing row is updated with the latest flags so
        // the UI doesn't need a separate edit affordance.
        SummaryReportSubscriber::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => strtolower(trim($this->email))],
            [
                'weekly' => $this->weekly,
                'monthly' => $this->monthly,
                'created_by_operator_id' => auth('web')->id(),
            ],
        );

        $this->reset(['email']);
        $this->weekly = true;
        $this->monthly = true;
        $this->flash = 'Added.';
    }

    public function toggle(int $id, string $field): void
    {
        if (! in_array($field, ['weekly', 'monthly'], true)) {
            return;
        }
        $sub = SummaryReportSubscriber::query()->find($id);
        if (! $sub) {
            return;
        }
        $sub->{$field} = ! $sub->{$field};
        $sub->save();
    }

    public function remove(int $id): void
    {
        SummaryReportSubscriber::query()->where('id', $id)->delete();
        $this->flash = 'Removed.';
    }

    /**
     * Send a "this month to date" PDF report to a single email on
     * demand so the manager can validate delivery + template before
     * the scheduled runs go out.
     *
     * The mailable implements ShouldQueue for the scheduled path (one
     * job per subscriber, worker eats them). For the interactive test
     * we override the connection to `sync` so the send fires
     * immediately in-process, no dependency on a running queue
     * worker. Errors surface as a UI flash so a bad MAIL_MAILER
     * config doesn't fail silently.
     */
    public function sendTest(BuildSummaryReport $builder): void
    {
        $this->validate(['testEmail' => 'required|email:rfc|max:190']);

        $tenant = Tenant::current();
        if (! $tenant) {
            return;
        }

        $recipient = trim($this->testEmail);

        try {
            $from = Carbon::today()->startOfMonth();
            $to = Carbon::today();
            $payload = $builder->handle($tenant, $from, $to, 'Preview');
            $pdfBytes = Pdf::loadView('pdfs.summary-report', $payload)->setPaper('a4')->output();

            $mailable = (new SummaryReportMail(
                tenant: $tenant,
                periodLabel: 'Preview',
                periodRange: $from->format('M j').' – '.$to->format('M j, Y'),
                pdfBytes: $pdfBytes,
                filename: 'summary-'.$tenant->slug.'-preview-'.$from->format('Y-m-d').'.pdf',
            ))->onConnection('sync');

            Mail::to($recipient)->send($mailable);
        } catch (\Throwable $e) {
            report($e);
            $this->flash = 'Send failed: '.$e->getMessage();

            return;
        }

        $this->flash = 'Test report sent to '.$recipient.'.';
        $this->testEmail = '';
    }

    public function render(): View
    {
        $subs = SummaryReportSubscriber::query()
            ->with('createdBy:id,name')
            ->orderBy('email')
            ->get();

        // If the current operator has an email, prefill the test-send
        // field so the "send me one" case is one click.
        $operator = auth('web')->user();
        if ($this->testEmail === '' && $operator instanceof Operator && $operator->email) {
            $this->testEmail = $operator->email;
        }

        return view('livewire.dashboard.summary.subscribers', [
            'subs' => $subs,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Newsletter;

use App\Models\NewsletterSubscription;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Newsletter subscriber list. Admins triage / bounce / manual
 * unsubscribe from here + hit "Export CSV" to hand off to any
 * outbound mailer.
 */
#[Layout('admin.layout')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'active';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function markStatus(int $id, string $status): void
    {
        if (! in_array($status, ['active', 'unsubscribed', 'bounced'], true)) return;
        $sub = NewsletterSubscription::query()->find($id);
        if ($sub === null) return;

        $sub->forceFill([
            'status' => $status,
            'unsubscribed_at' => $status === 'active' ? null : now(),
        ])->save();

        activity('newsletter_subscription')
            ->causedBy(auth('admin')->user())
            ->performedOn($sub)
            ->log("Admin marked {$sub->email} as {$status}.");
    }

    public function render(): View
    {
        return view('livewire.admin.newsletter.index', [
            'subs' => $this->paginate(),
            'statuses' => ['active', 'unsubscribed', 'bounced'],
            'totals' => [
                'active'       => NewsletterSubscription::query()->where('status', 'active')->count(),
                'unsubscribed' => NewsletterSubscription::query()->where('status', 'unsubscribed')->count(),
                'bounced'      => NewsletterSubscription::query()->where('status', 'bounced')->count(),
            ],
        ]);
    }

    private function paginate(): LengthAwarePaginator
    {
        $query = NewsletterSubscription::query()->orderBy('id', 'desc');

        $needle = trim($this->search);
        if ($needle !== '') {
            $query->where('email', 'like', '%'.$needle.'%');
        }
        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return $query->paginate(50);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ApiAccessRequests;

use App\Models\ApiAccessRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin queue for /api-access submissions. Sales/dev-rel triages
 * status here; once beta opens the "approved" rows get client IDs
 * issued via a separate console (out of scope for the marketing
 * prototype).
 */
#[Layout('admin.layout')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'new';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function markStatus(int $id, string $status): void
    {
        if (! in_array($status, ['new', 'contacted', 'approved', 'rejected'], true)) return;
        $req = ApiAccessRequest::query()->find($id);
        if ($req === null) return;
        $req->forceFill(['status' => $status])->save();

        activity('api_access_request')
            ->causedBy(auth('admin')->user())
            ->performedOn($req)
            ->log("API request marked {$status}.");
    }

    public function render(): View
    {
        return view('livewire.admin.api-access-requests.index', [
            'requests' => $this->paginate(),
            'statuses' => ['new', 'contacted', 'approved', 'rejected'],
        ]);
    }

    private function paginate(): LengthAwarePaginator
    {
        $query = ApiAccessRequest::query()->orderBy('id', 'desc');

        $needle = trim($this->search);
        if ($needle !== '') {
            $query->where(function ($q) use ($needle): void {
                $q->where('name', 'like', '%'.$needle.'%')
                  ->orWhere('email', 'like', '%'.$needle.'%')
                  ->orWhere('company', 'like', '%'.$needle.'%')
                  ->orWhere('use_case', 'like', '%'.$needle.'%');
            });
        }
        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return $query->paginate(30);
    }
}

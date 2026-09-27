<?php

declare(strict_types=1);

namespace App\Livewire\Admin\DemoRequests;

use App\Models\Admin;
use App\Models\DemoRequest;
use App\Models\LicenseCode;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sales queue for /demo submissions. Admins work leads top-down,
 * mark status changes, and tap "Issue licence code" to generate a
 * fresh code the prospect can redeem at /register. Code is shown
 * once in-page so the admin can copy it into WhatsApp/email.
 */
#[Layout('admin.layout')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'new';

    /** The most-recent code issued this session, keyed by demo id.
     *  Presented as a "copy me" flash + a compact info line so the
     *  admin has one round-trip between issuing and messaging. */
    public array $issuedCodes = [];

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function markStatus(int $id, string $status): void
    {
        if (! in_array($status, ['new', 'contacted', 'demo_booked', 'converted', 'passed'], true)) {
            return;
        }
        $req = DemoRequest::query()->find($id);
        if ($req === null) return;
        $req->forceFill(['status' => $status])->save();

        activity('demo_request')
            ->causedBy(auth('admin')->user())
            ->performedOn($req)
            ->log("Demo request marked {$status}.");
    }

    public function issueCode(int $id): void
    {
        $req = DemoRequest::query()->find($id);
        if ($req === null) return;
        $admin = auth('admin')->user();

        $code = LicenseCode::query()->create([
            'code'              => LicenseCode::generateCode(),
            'issued_to_email'   => $req->email,
            'issued_to_name'    => $req->name,
            'demo_request_id'   => $req->id,
            'status'            => 'unused',
            'expires_at'        => now()->addDays(30),
            'issued_by_admin_id' => $admin instanceof Admin ? $admin->getKey() : null,
            'notes'             => 'Issued from demo-queue for '.$req->property_name.'.',
        ]);

        // Move the demo along to demo_booked if it's still 'new' -
        // an admin issuing a code is a strong signal they've had the
        // call. Explicit 'contacted'/'converted' still stick.
        if ($req->status === 'new') {
            $req->forceFill(['status' => 'demo_booked'])->save();
        }

        activity('demo_request')
            ->causedBy($admin)
            ->performedOn($req)
            ->withProperties(['code' => $code->code])
            ->log("Issued licence code {$code->code} to {$req->name}.");

        $this->issuedCodes[$req->id] = $code->code;
    }

    public function render(): View
    {
        return view('livewire.admin.demo-requests.index', [
            'requests' => $this->paginate(),
            'statuses' => ['new', 'contacted', 'demo_booked', 'converted', 'passed'],
        ]);
    }

    private function paginate(): LengthAwarePaginator
    {
        $query = DemoRequest::query()->orderBy('id', 'desc');

        $needle = trim($this->search);
        if ($needle !== '') {
            $query->where(function ($q) use ($needle): void {
                $q->where('name', 'like', '%'.$needle.'%')
                  ->orWhere('email', 'like', '%'.$needle.'%')
                  ->orWhere('property_name', 'like', '%'.$needle.'%')
                  ->orWhere('phone', 'like', '%'.$needle.'%');
            });
        }
        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return $query->paginate(30);
    }
}

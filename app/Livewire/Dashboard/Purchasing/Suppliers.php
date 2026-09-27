<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Purchasing;

use App\Models\Supplier;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Supplier directory. List + inline drawer for create/edit. Small model,
 * no separate detail page, the PO index carries the historical view.
 */
#[Layout('layouts.tenant-auth')]
class Suppliers extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';
    public string $contactName = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $notes = '';
    public bool $active = true;
    public bool $showForm = false;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $s = Supplier::query()->findOrFail($id);
        $this->editingId  = $s->id;
        $this->name       = $s->name;
        $this->contactName = (string) ($s->contact_name ?? '');
        $this->phone      = (string) ($s->phone ?? '');
        $this->email      = (string) ($s->email ?? '');
        $this->address    = (string) ($s->address ?? '');
        $this->notes      = (string) ($s->notes ?? '');
        $this->active     = $s->active;
        $this->showForm   = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name'        => ['required', 'string', 'max:160'],
            'contactName' => ['nullable', 'string', 'max:120'],
            'phone'       => ['nullable', 'string', 'max:40'],
            'email'       => ['nullable', 'email', 'max:160'],
            'address'     => ['nullable', 'string', 'max:240'],
            'notes'       => ['nullable', 'string', 'max:2000'],
            'active'      => ['boolean'],
        ]);

        $payload = [
            'name'         => $data['name'],
            'contact_name' => $data['contactName'] ?: null,
            'phone'        => $data['phone'] ?: null,
            'email'        => $data['email'] ?: null,
            'address'      => $data['address'] ?: null,
            'notes'        => $data['notes'] ?: null,
            'active'       => (bool) $data['active'],
        ];

        if ($this->editingId) {
            Supplier::query()->findOrFail($this->editingId)->update($payload);
            session()->flash('status', 'Supplier updated.');
        } else {
            Supplier::query()->create($payload);
            session()->flash('status', 'Supplier added.');
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->contactName = '';
        $this->phone = '';
        $this->email = '';
        $this->address = '';
        $this->notes = '';
        $this->active = true;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $suppliers = Supplier::query()
            ->when($this->search !== '', function ($q): void {
                $like = '%'.trim($this->search).'%';
                $q->where(function ($qq) use ($like): void {
                    $qq->where('name', 'like', $like)
                        ->orWhere('contact_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->withCount(['purchaseOrders as open_po_count' => fn ($q) => $q->whereIn('status', ['sent', 'partially_received'])])
            ->orderBy('name')
            ->get();

        return view('livewire.dashboard.purchasing.suppliers', [
            'suppliers' => $suppliers,
        ]);
    }
}

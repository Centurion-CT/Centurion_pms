<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Corporates;

use App\Enums\CorporateKycStatus;
use App\Enums\CorporateStatus;
use App\Models\Corporate;
use App\Services\Corporates\WalletService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('admin.layout')]
class Form extends Component
{
    #[Locked]
    public ?int $corporateId = null;

    public string $name = '';
    public string $primary_email = '';
    public string $primary_phone = '';
    public string $billing_email = '';
    public string $address = '';
    public string $city = '';
    public string $country = 'NG';
    public string $registration_number = '';
    public string $tax_id = '';
    public string $status = 'onboarding';
    public string $kyc_status = 'pending';
    public string $kyc_notes = '';
    public ?int $approval_threshold_naira = null;
    public string $notes = '';

    public function mount(?Corporate $corporate = null): void
    {
        if ($corporate !== null && $corporate->exists) {
            $this->corporateId               = $corporate->id;
            $this->name                      = $corporate->name;
            $this->primary_email             = $corporate->primary_email;
            $this->primary_phone             = (string) $corporate->primary_phone;
            $this->billing_email             = (string) $corporate->billing_email;
            $this->address                   = (string) $corporate->address;
            $this->city                      = (string) $corporate->city;
            $this->country                   = $corporate->country;
            $this->registration_number       = (string) $corporate->registration_number;
            $this->tax_id                    = (string) $corporate->tax_id;
            $this->status                    = $corporate->status->value;
            $this->kyc_status                = $corporate->kyc_status->value;
            $this->kyc_notes                 = (string) $corporate->kyc_notes;
            $this->approval_threshold_naira  = $corporate->approval_threshold_naira;
            $this->notes                     = (string) $corporate->notes;
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function rules(): array
    {
        return [
            'name'                      => ['required', 'string', 'max:160'],
            'primary_email'             => ['required', 'email', 'max:180'],
            'primary_phone'             => ['nullable', 'string', 'max:32'],
            'billing_email'             => ['nullable', 'email', 'max:180'],
            'address'                   => ['nullable', 'string', 'max:240'],
            'city'                      => ['nullable', 'string', 'max:80'],
            'country'                   => ['required', 'string', 'size:2'],
            'registration_number'       => ['nullable', 'string', 'max:64'],
            'tax_id'                    => ['nullable', 'string', 'max:64'],
            'status'                    => ['required', Rule::in(array_map(fn ($c) => $c->value, CorporateStatus::cases()))],
            'kyc_status'                => ['required', Rule::in(array_map(fn ($c) => $c->value, CorporateKycStatus::cases()))],
            'kyc_notes'                 => ['nullable', 'string', 'max:5000'],
            'approval_threshold_naira'  => ['nullable', 'integer', 'min:0'],
            'notes'                     => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function save(WalletService $wallets): void
    {
        $data = $this->validate();

        $corporate = $this->corporateId !== null
            ? Corporate::query()->findOrFail($this->corporateId)
            : new Corporate;

        if (! $corporate->exists) {
            $corporate->slug = $this->uniqueSlug($data['name']);
        }

        $corporate->fill($data)->save();

        // Make sure a wallet exists from day one so members can be added
        // and admins can top up immediately.
        $wallets->ensureWallet($corporate);

        activity('admin')
            ->causedBy(auth('admin')->user())
            ->performedOn($corporate)
            ->event($this->corporateId === null ? 'created' : 'updated')
            ->log($this->corporateId === null ? 'Created corporate' : 'Updated corporate');

        session()->flash('status', $this->corporateId === null ? 'Corporate created.' : 'Corporate updated.');

        $this->redirectRoute('admin.corporates.show', ['corporate' => $corporate->slug], navigate: true);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'corporate';
        $slug = $base;
        $i = 1;
        while (Corporate::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    public function render(): View
    {
        return view('livewire.admin.corporates.form', [
            'isEdit'        => $this->corporateId !== null,
            'statusOptions' => CorporateStatus::cases(),
            'kycOptions'    => CorporateKycStatus::cases(),
        ]);
    }
}

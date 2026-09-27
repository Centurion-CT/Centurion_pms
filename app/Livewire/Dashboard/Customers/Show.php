<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Customers;

use App\Enums\CustomerInteractionDirection;
use App\Enums\CustomerInteractionType;
use App\Enums\PosSaleStatus;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\PosSale;
use App\Models\PosSaleLine;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * CRM customer profile. Shows the rolled-up profile + bookings timeline
 * + interactions timeline + a form for the operator to log a new
 * interaction (note / call / email / WhatsApp / SMS / in-person).
 *
 * The "add interaction" form is intentionally inline (not a modal) so it
 * never gets in the way of seeing the existing timeline as the operator
 * is typing.
 */
#[Layout('layouts.tenant-auth')]
class Show extends Component
{
    #[Locked]
    public int $customerId;

    #[Validate('required|in:note,call,email,whatsapp,sms,in_person')]
    public string $newType = 'note';

    #[Validate('required|in:inbound,outbound,none')]
    public string $newDirection = 'none';

    #[Validate('nullable|string|max:200')]
    public string $newSubject = '';

    #[Validate('required|string|max:4000')]
    public string $newBody = '';

    public function mount(Customer $customer): void
    {
        $this->customerId = $customer->id;
        $this->newDirection = $this->defaultDirectionFor($this->newType);
    }

    public function updatedNewType(string $value): void
    {
        // When the type changes, pre-pick the most likely direction so
        // operators don't have to flip two selects in a row.
        $this->newDirection = $this->defaultDirectionFor($value);
    }

    public function logInteraction(): void
    {
        $this->validate();

        $customer = $this->customer();
        $operator = auth('web')->user();

        CustomerInteraction::query()->create([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'created_by_operator_id' => $operator?->getKey(),
            'type' => $this->newType,
            'direction' => $this->newDirection,
            'subject' => $this->newSubject !== '' ? $this->newSubject : null,
            'body' => $this->newBody,
            'happened_at' => Carbon::now(),
        ]);

        // Reset just the body, operators often log a quick follow-up in
        // the same type/direction.
        $this->newSubject = '';
        $this->newBody = '';
        $this->dispatch('notify', title: 'Done', message: 'Interaction logged.', kind: 'ok');
    }

    public function toggleVip(): void
    {
        $customer = $this->customer();
        $customer->is_vip = ! $customer->is_vip;
        $customer->save();
        $this->dispatch('notify', title: 'Done', message: $customer->is_vip ? 'Marked VIP.' : 'VIP flag cleared.', kind: 'ok');
    }

    public function render(): View
    {
        $customer = $this->customer();

        $bookings = $customer->bookings()->with('room')->get();
        $interactions = $customer->interactions()->with('operator', 'booking')->get();

        // POS purchase history. Only Completed sales (drafts + voided
        // don't count as "purchases"). Complimentary sales are surfaced
        // separately so the KPI strip can split paid vs comp.
        $purchases = PosSale::query()
            ->where('customer_id', $customer->id)
            ->where('status', PosSaleStatus::Completed->value)
            ->with(['outlet:id,name', 'operator:id,name', 'lines:id,pos_sale_id,name,quantity,unit_price,subtotal,is_complimentary', 'payments'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $paidValue = (int) $purchases->where('is_complimentary', false)->sum('total');
        // For comps, sum the line subtotals because pos_sales.total is 0
        // for comped sales (that's the whole point — no money changed hands).
        $compSaleIds = $purchases->where('is_complimentary', true)->pluck('id');
        $compValue = $compSaleIds->isEmpty() ? 0 : (int) PosSaleLine::query()
            ->whereIn('pos_sale_id', $compSaleIds)
            ->sum('subtotal');
        $purchasesKpi = [
            'count' => $purchases->count(),
            'paid_count' => $purchases->where('is_complimentary', false)->count(),
            'paid_value' => $paidValue,
            'comp_count' => $compSaleIds->count(),
            'comp_value' => $compValue,
            'last_purchase_at' => $purchases->first()?->created_at,
        ];

        return view('livewire.dashboard.customers.show', [
            'customer' => $customer,
            'bookings' => $bookings,
            'interactions' => $interactions,
            'purchases' => $purchases,
            'purchasesKpi' => $purchasesKpi,
            'typeOptions' => CustomerInteractionType::cases(),
            'directionOptions' => CustomerInteractionDirection::cases(),
        ]);
    }

    private function defaultDirectionFor(string $type): string
    {
        return match ($type) {
            'note', 'in_person' => CustomerInteractionDirection::None->value,
            default             => CustomerInteractionDirection::Outbound->value,
        };
    }

    private function customer(): Customer
    {
        // Scope to the operator's current branch so a URL-guess into
        // another property's Customer row 404s instead of silently
        // exposing CRM data across branches.
        return Customer::query()->forCurrentProperty()->findOrFail($this->customerId);
    }
}

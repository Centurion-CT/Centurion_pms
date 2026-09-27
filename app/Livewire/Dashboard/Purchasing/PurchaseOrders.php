<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Purchase-order listing. Filters by status + supplier + outlet.
 * Table only, the composer lives at `tenant.purchasing.orders.create`
 * and the detail page handles receipts.
 */
#[Layout('layouts.tenant-auth')]
class PurchaseOrders extends Component
{
    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'supplier', except: '')]
    public string $supplierFilter = '';

    #[Url(as: 'outlet', except: '')]
    public string $outletFilter = '';

    public function render(): View
    {
        $orders = PurchaseOrder::query()
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->supplierFilter !== '', fn ($q) => $q->where('supplier_id', (int) $this->supplierFilter))
            ->when($this->outletFilter !== '', fn ($q) => $q->where('outlet_id', (int) $this->outletFilter))
            ->with(['supplier:id,name', 'outlet:id,name'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $openCount = (int) PurchaseOrder::query()->open()->count();
        $draftCount = (int) PurchaseOrder::query()->where('status', PurchaseOrderStatus::Draft->value)->count();
        $spend30d = (int) PurchaseOrder::query()
            ->where('status', PurchaseOrderStatus::Received->value)
            ->where('received_at', '>=', now()->subDays(30))
            ->sum('total');

        return view('livewire.dashboard.purchasing.purchase-orders', [
            'orders'     => $orders,
            'suppliers'  => Supplier::query()->orderBy('name')->get(),
            'outlets'    => Outlet::query()->orderBy('name')->get(),
            'statuses'   => PurchaseOrderStatus::cases(),
            'openCount'  => $openCount,
            'draftCount' => $draftCount,
            'spend30d'   => $spend30d,
        ]);
    }
}

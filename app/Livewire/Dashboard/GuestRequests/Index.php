<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\GuestRequests;

use App\Enums\GuestRequestKind;
use App\Livewire\Concerns\HandlesBulkSelection;
use App\Models\GuestRequest;
use App\Models\GuestRequestMessage;
use App\Models\Operator;
use App\Models\RoomCategory;
use App\Services\Properties\PropertyContext;
use App\Services\GuestPortal\GuestNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Operator queue for guest-initiated requests raised through the
 * in-room QR portal. Open requests bubble to the top; resolved ones
 * fade under the "Resolved" tab so the queue stays clean.
 *
 * Scoped to the branch the operator is currently working in — a
 * Lagos-branch operator doesn't see Abuja's complaint queue.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use HandlesBulkSelection;

    #[Url(as: 'status', except: 'open')]
    public string $statusFilter = 'open';

    #[Url(as: 'kind', except: '')]
    public string $kindFilter = '';

    /** Detail panel state — clicking a row sets this and the blade
     *  slides the panel in. Null = closed. */
    public ?int $selectedRequestId = null;

    /** Operator's draft message on the panel — cleared after post. */
    public string $newMessage = '';

    public function openRequest(int $id): void
    {
        $this->selectedRequestId = $id;
        $this->newMessage = '';
    }

    public function closeRequest(): void
    {
        $this->selectedRequestId = null;
        $this->newMessage = '';
    }

    public function sendMessage(): void
    {
        $body = trim($this->newMessage);
        if ($body === '' || $this->selectedRequestId === null) {
            return;
        }
        $request = GuestRequest::query()->find($this->selectedRequestId);
        if ($request === null) return;
        $operator = auth('web')->user();

        GuestRequestMessage::query()->create([
            'tenant_id' => $request->tenant_id,
            'guest_request_id' => $request->id,
            'sender_type' => 'operator',
            'sender_operator_id' => $operator instanceof Operator ? $operator->getKey() : null,
            'message' => $body,
        ]);

        activity('guest_request')
            ->causedBy($operator)
            ->performedOn($request)
            ->event('message_sent')
            ->log("Operator replied on request {$request->id}.");

        $this->newMessage = '';
    }

    public function resolve(int $id, \App\Services\GuestPortal\GuestNotifier $notifier): void
    {
        $request = GuestRequest::query()->with('booking.room.property')->find($id);
        if ($request === null) return;
        $operator = auth('web')->user();
        $request->forceFill([
            'status' => 'resolved',
            'resolved_by_operator_id' => $operator instanceof Operator ? $operator->getKey() : null,
            'resolved_at' => Carbon::now(),
        ])->save();

        // Best-effort guest WhatsApp notification. Skips silently
        // when the guest phone is missing or Sendpipe isn't
        // configured; the notifier itself catches delivery failures
        // so this action never bubbles a network error to the UI.
        $phone = (string) ($request->booking?->guest_phone ?? '');
        if ($phone !== '') {
            $property = $request->booking?->room?->property;
            $message = sprintf(
                "%s: your %s has been sorted. Reach out if there's anything else.",
                $property?->name ?? 'Reception',
                mb_strtolower($request->kind->label()),
            );
            try {
                $notifier->sendRequestResolvedNotice($phone, $message);
            } catch (\Throwable) {
                // Notifier already catches + logs; nothing more here.
            }
        }

        session()->flash('status', 'Marked resolved.');
    }

    public function reopen(int $id): void
    {
        $request = GuestRequest::query()->find($id);
        if ($request === null) return;
        $request->forceFill([
            'status' => 'open',
            'resolved_by_operator_id' => null,
            'resolved_at' => null,
        ])->save();
    }

    /**
     * Bulk mark-as-resolved for every checked row. Each request is
     * resolved individually (reusing resolve() so the WhatsApp notice +
     * activity log fire per guest); silently skips rows that were
     * already resolved or that no longer belong to the current tenant.
     */
    public function resolveSelected(GuestNotifier $notifier): void
    {
        if ($this->selectedIds === []) return;
        $ids = $this->selectedIds;
        $this->clearSelection();

        $processed = 0;
        foreach ($ids as $id) {
            $intId = (int) $id;
            $before = GuestRequest::query()->find($intId);
            if ($before === null || $before->status === 'resolved') continue;
            $this->resolve($intId, $notifier);
            $processed++;
        }
        session()->flash('status', sprintf(
            'Marked %d %s resolved.',
            $processed,
            $processed === 1 ? 'request' : 'requests',
        ));
    }

    /**
     * Global Esc-to-clear hook. The <x-dash-bulk-bar> component's
     * inline JS dispatches this event when the operator hits Escape
     * outside an input; every index screen that uses the trait can
     * safely listen — an idle listener with no selection is a no-op.
     */
    #[On('bulk-clear')]
    public function onBulkClear(): void
    {
        $this->clearSelection();
    }

    public function render(): View
    {
        $propertyId = app(PropertyContext::class)->current()?->getKey();

        $query = GuestRequest::query()
            ->with([
                'booking:id,booking_ref,guest_name,guest_phone,guest_email,checkin_date,checkout_date,room_id',
                'booking.room:id,room_number,room_category_id',
                'booking.room.category:id,name',
                'room:id,room_number',
                'resolvedBy:id,name',
            ])
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
            ->orderByDesc('id');

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }
        if ($this->kindFilter !== '' && GuestRequestKind::tryFrom($this->kindFilter)) {
            $query->where('kind', $this->kindFilter);
        }

        $paginated = $query->paginate(25);

        // Preload category names once so the list + panel resolve
        // `body.target_category_id` → "Deluxe" instead of showing a raw
        // integer to the front desk.
        $categoryIds = [];
        foreach ($paginated->items() as $r) {
            $tid = $r->body['target_category_id'] ?? null;
            if (is_numeric($tid)) $categoryIds[] = (int) $tid;
        }
        $categoryNames = $categoryIds !== []
            ? RoomCategory::query()->whereIn('id', array_unique($categoryIds))->pluck('name', 'id')->all()
            : [];

        $selected = null;
        $messages = collect();
        if ($this->selectedRequestId !== null) {
            $selected = GuestRequest::query()
                ->with([
                    'booking:id,booking_ref,guest_name,guest_phone,guest_email,checkin_date,checkout_date,room_id',
                    'booking.room:id,room_number,room_category_id',
                    'booking.room.category:id,name',
                    'room:id,room_number',
                    'resolvedBy:id,name',
                ])
                ->find($this->selectedRequestId);
            if ($selected !== null) {
                $messages = $selected->messages()
                    ->with('operator:id,name')
                    ->orderBy('created_at')
                    ->get();
            }
        }

        return view('livewire.dashboard.guest-requests.index', [
            'requests' => $paginated,
            'kinds' => GuestRequestKind::cases(),
            'categoryNames' => $categoryNames,
            'selected' => $selected,
            'messages' => $messages,
        ]);
    }
}

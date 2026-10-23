<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Actions\GuestRequests\ReplyToGuestRequest;
use App\Actions\GuestRequests\ResolveGuestRequest;
use App\Enums\GuestRequestKind;
use App\Models\GuestRequest;
use App\Models\GuestRequestMessage;
use App\Models\RoomCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Host app guest requests (docs/host-app/PHASE-3.md, 20 / 20b): the
 * in-room portal queue of the bound branch, the thread, and the same
 * reply / resolve / reopen actions the web Guest requests page runs.
 * Web gate: role:reservations.
 */
class GuestRequestsController extends HostController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved'])],
            'kind' => ['nullable', Rule::in(array_map(fn (GuestRequestKind $k) => $k->value, GuestRequestKind::cases()))],
        ]);
        if (! $this->allowed($request)) {
            return $this->forbidden();
        }
        $status = $data['status'] ?? 'open';

        $rows = $this->scoped($request)
            ->with(['booking:id,booking_ref,guest_name,guest_phone,room_id', 'room:id,room_number', 'resolvedBy:id,name'])
            ->where('status', $status)
            ->when($data['kind'] ?? null, fn (Builder $q, string $kind) => $q->where('kind', $kind))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $today = Carbon::today();
        $open = $this->scoped($request)->where('status', 'open');

        return response()->json([
            'data' => $rows->map(fn (GuestRequest $r) => $this->row($r))->values()->all(),
            'counts' => [
                'open' => (clone $open)->count(),
                'urgent' => (clone $open)->whereIn('kind', self::urgentKinds())->count(),
                'resolved_today' => $this->scoped($request)->where('status', 'resolved')->where('resolved_at', '>=', $today)->count(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->allowed($request)) {
            return $this->forbidden();
        }
        $req = $this->find($request, $id);
        if ($req === null) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->detail($req)]);
    }

    public function reply(Request $request, int $id, ReplyToGuestRequest $reply): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.ReplyToGuestRequest::MAX_LENGTH]]);
        if (! $this->allowed($request)) {
            return $this->forbidden();
        }
        $req = $this->find($request, $id);
        if ($req === null) {
            return $this->notFound();
        }

        try {
            $reply->handle($req, $this->operator($request), (string) $data['body']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'reply_failed', 'detail' => $e->getMessage()], 422);
        }

        return $this->ok($req);
    }

    public function resolve(Request $request, int $id, ResolveGuestRequest $resolve): JsonResponse
    {
        if (! $this->allowed($request)) {
            return $this->forbidden();
        }
        $req = $this->find($request, $id);
        if ($req === null) {
            return $this->notFound();
        }
        if ($req->status === 'resolved') {
            return response()->json(['error' => 'already_resolved', 'detail' => 'This request is already resolved.'], 409);
        }

        $resolve->handle($req, $this->operator($request));

        return $this->ok($req);
    }

    public function reopen(Request $request, int $id, ResolveGuestRequest $resolve): JsonResponse
    {
        if (! $this->allowed($request)) {
            return $this->forbidden();
        }
        $req = $this->find($request, $id);
        if ($req === null) {
            return $this->notFound();
        }
        if ($req->status === 'open') {
            return response()->json(['error' => 'already_open', 'detail' => 'This request is already open.'], 409);
        }

        $resolve->reopen($req);

        return $this->ok($req);
    }

    // ── Shapes ───────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function row(GuestRequest $r): array
    {
        $notes = trim((string) ($r->notes ?? ''));

        return [
            'id' => (int) $r->id,
            'kind' => $r->kind->value,
            'kind_label' => $r->kind->label(),
            'urgent' => $r->kind->isUrgent(),
            'status' => (string) $r->status,
            'room_number' => $r->room?->room_number,
            'guest_name' => $r->booking?->guest_name,
            'booking_ref' => $r->booking?->booking_ref,
            'preview' => $notes !== '' ? Str::limit($notes, 120) : $r->kind->label(),
            'created_at' => $r->created_at?->toIso8601String(),
            'resolved_at' => $r->resolved_at?->toIso8601String(),
            'resolved_by' => $r->resolvedBy?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(GuestRequest $r): array
    {
        $r->loadMissing(['booking:id,booking_ref,guest_name,guest_phone,room_id', 'room:id,room_number', 'resolvedBy:id,name']);
        $messages = $r->messages()->with('operator:id,name')->orderBy('created_at')->orderBy('id')->get();

        return $this->row($r) + [
            'body' => $r->notes,
            'details' => $this->details($r),
            'guest_phone' => $r->booking?->guest_phone,
            'messages' => $messages->map(fn (GuestRequestMessage $m) => [
                'id' => (int) $m->id,
                'from' => $m->isFromOperator() ? 'staff' : 'guest',
                'author' => $m->isFromOperator()
                    ? ($m->operator?->name ?? 'Front desk')
                    : ($r->booking?->guest_name ?? 'Guest'),
                'body' => (string) $m->message,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
            'quick_replies' => self::quickReplies($r->kind),
        ];
    }

    /**
     * The request's structured body as label / value pairs, with the
     * transfer target category resolved to its name like the web panel.
     *
     * @return list<array{label: string, value: string}>
     */
    private function details(GuestRequest $r): array
    {
        $out = [];
        foreach ((array) ($r->body ?? []) as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            if ($key === 'target_category_id' && is_numeric($value)) {
                $out[] = ['label' => 'Wants', 'value' => (string) (RoomCategory::query()->whereKey((int) $value)->value('name') ?? $value)];

                continue;
            }
            if (str_ends_with((string) $key, '_id')) {
                continue;
            }
            $out[] = ['label' => ucfirst(str_replace('_', ' ', (string) $key)), 'value' => is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function quickReplies(GuestRequestKind $kind): array
    {
        return match ($kind) {
            GuestRequestKind::Complaint => ['Sorry about that, someone is on the way.', 'We are looking into it now.', 'It is sorted. Let us know if anything else comes up.'],
            GuestRequestKind::Taxi => ['Your taxi is booked.', 'Your taxi will be at reception in 15 minutes.', 'Where would you like to go?'],
            GuestRequestKind::Transfer => ['We are checking which rooms are free.', 'Your new room is ready, please come to reception.', 'Sorry, we have no free room in that category today.'],
            GuestRequestKind::Order => ['Your order is on the way.', 'Your order will be ready in 20 minutes.', 'Sorry, that item is not available right now.'],
            GuestRequestKind::Custom => ['On it.', 'Someone will be with you shortly.', 'Done. Anything else?'],
        };
    }

    /** @return list<string> */
    private static function urgentKinds(): array
    {
        return array_values(array_map(
            fn (GuestRequestKind $k) => $k->value,
            array_filter(GuestRequestKind::cases(), fn (GuestRequestKind $k) => $k->isUrgent()),
        ));
    }

    // ── Plumbing ─────────────────────────────────────────────────

    /**
     * Requests on the branch's rooms, valid kinds only.
     *
     * @return Builder<GuestRequest>
     */
    private function scoped(Request $request): Builder
    {
        $pid = (int) $this->property($request)->id;

        return GuestRequest::query()
            ->whereIn('kind', array_map(fn (GuestRequestKind $k) => $k->value, GuestRequestKind::cases()))
            ->whereHas('room', fn ($r) => $r->where('property_id', $pid));
    }

    private function find(Request $request, int $id): ?GuestRequest
    {
        return $this->scoped($request)->whereKey($id)->first();
    }

    private function allowed(Request $request): bool
    {
        return $this->operator($request)->canAccessModule('reservations');
    }

    private function ok(GuestRequest $req): JsonResponse
    {
        $fresh = GuestRequest::query()->findOrFail($req->id);

        return response()->json(['status' => 'ok', 'request' => $this->detail($fresh)]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found', 'detail' => 'Request not found in this branch.'], 404);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['error' => 'forbidden', 'detail' => 'Your role can not do this.'], 403);
    }
}

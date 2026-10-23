<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Host app Alerts (docs/host-app/PHASE-3.md, 21): the same merged feed
 * as the web sidebar bell ({@see NotificationFeed}).
 *
 * Page 1 = branch activity since the operator last cleared the bell
 * (always unread) + the newest database notifications; later pages
 * page through older database notifications. Activity items are only
 * cleared by read-all (like the web "Mark all read"); `read` with ids
 * marks database notifications.
 */
class NotificationsController extends HostController
{
    public const PER_PAGE = 20;

    public function __construct(private readonly NotificationFeed $feed) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:500']]);
        $page = (int) ($data['page'] ?? 1);
        $operator = $this->operator($request);
        $pid = (int) $this->property($request)->id;

        if ($page === 1) {
            $built = $this->feed->build($operator, $pid, self::PER_PAGE);
            $items = $built['items'];
            $unread = $built['count'];
        } else {
            $items = $this->feed->databaseItems($operator, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            $unread = $this->feed->unreadCount($operator, $pid);
        }

        $dbTotal = $this->feed->databaseNotifications($operator)->count();

        return response()->json([
            'data' => $items->map(fn (array $i) => self::present($i))->values()->all(),
            'unread' => $unread,
            'next_page' => $dbTotal > $page * self::PER_PAGE ? $page + 1 : null,
        ]);
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['string', 'max:64'],
        ]);
        $operator = $this->operator($request);
        // Activity ids (request:, approval:, activity:, alert:) are only
        // cleared by read-all; database notification ids are uuids.
        $dbIds = array_values(array_filter($data['ids'], fn (string $id) => ! str_contains($id, ':')));
        $this->feed->markRead($operator, $dbIds);

        return $this->ok($request);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->feed->markAllSeen($this->operator($request));

        return $this->ok($request);
    }

    /**
     * @param  array<string, mixed>  $i
     * @return array<string, mixed>
     */
    public static function present(array $i): array
    {
        if (($i['source'] ?? null) === 'database') {
            return [
                'id' => (string) $i['id'],
                'kind' => 'notification',
                'icon_kind' => match ($i['event'] ?? null) {
                    'confirmed' => 'booking',
                    'cancelled' => 'cancel',
                    default => 'bell',
                },
                'title' => (string) $i['title'],
                'body' => (string) $i['message'],
                'booking_ref' => $i['booking_ref'] ?? null,
                'created_at' => $i['at']?->toIso8601String(),
                'read' => ! ($i['unread'] ?? false),
            ];
        }

        $kind = (string) ($i['kind'] ?? 'activity');

        return [
            'id' => (string) ($i['ref'] ?? ''),
            'kind' => $kind,
            'icon_kind' => match ($kind) {
                'guest_request' => 'request',
                'approval' => 'approval',
                'booking' => match ($i['event'] ?? null) {
                    'extended' => 'extend',
                    'checked_in' => 'check_in',
                    'checked_out' => 'check_out',
                    default => 'booking',
                },
                'store_request', 'low_stock' => 'stock',
                default => 'bell',
            },
            'title' => (string) ($i['kind_label'] ?? ''),
            'body' => trim((string) ($i['title'] ?? '').' · '.(string) ($i['subtitle'] ?? ''), ' ·'),
            'booking_ref' => $i['booking_ref'] ?? null,
            'created_at' => $i['at']?->toIso8601String(),
            'read' => false,
        ];
    }

    private function ok(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'unread' => $this->feed->unreadCount($this->operator($request), (int) $this->property($request)->id),
        ]);
    }
}

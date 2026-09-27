<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Operator;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Bell-icon dropdown for in-dashboard notifications (PRD §17).
 *
 * Shows the latest 10 notifications for the logged-in operator plus an
 * unread badge. Mark-read flips one row; mark-all-read flips every unread
 * row for this operator in a single update.
 *
 * No polling, the dropdown re-queries on toggle. Real-time push lives in
 * v2 (Echo/Reverb integration).
 */
class NotificationsBell extends Component
{
    public bool $open = false;

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function markRead(string $id): void
    {
        $operator = $this->operator();
        DatabaseNotification::query()
            ->where('notifiable_type', $operator->getMorphClass())
            ->where('notifiable_id', $operator->id)
            ->whereKey($id)
            ->update(['read_at' => Carbon::now()]);
    }

    public function markAllRead(): void
    {
        $operator = $this->operator();
        DatabaseNotification::query()
            ->where('notifiable_type', $operator->getMorphClass())
            ->where('notifiable_id', $operator->id)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);
    }

    public function render(): View
    {
        $operator = $this->operator();

        $base = DatabaseNotification::query()
            ->where('notifiable_type', $operator->getMorphClass())
            ->where('notifiable_id', $operator->id);

        $unread = (clone $base)->whereNull('read_at')->count();
        $items = (clone $base)->latest('created_at')->limit(10)->get();

        return view('livewire.dashboard.notifications-bell', [
            'items' => $items,
            'unreadCount' => $unread,
        ]);
    }

    private function operator(): Operator
    {
        /** @var Operator $op */
        $op = auth()->guard('web')->user();

        return $op;
    }
}

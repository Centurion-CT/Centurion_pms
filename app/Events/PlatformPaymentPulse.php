<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cross-tenant pulse fired every time a successful Payment lands
 * platform-wide. Powers the marketing kobo counter's live tick + the
 * floating "N90,000 just received, stays" toast on public surfaces.
 *
 * Deliberately carries no tenant name, guest info, booking ref, or
 * timestamp: the marketing surface is anonymous and any correlation
 * signal (property + amount + time) would leak booking activity to
 * a bystander who just paid. Amount is padded server-side by
 * PlatformStats::PUBLIC_PAD_FACTOR so the live tick stays coherent
 * with the padded baseline set by RecomputePlatformStats.
 */
class PlatformPaymentPulse implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  int  $paddedAmount  padded naira increment to add to the counter
     * @param  int  $roundedAmount  padded naira rounded for toast display
     * @param  string  $category  one of: stays, outlet, workspace, extra, ar
     */
    public function __construct(
        public int $paddedAmount,
        public int $roundedAmount,
        public string $category,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('platform-pulse');
    }

    public function broadcastAs(): string
    {
        return 'payment.pulse';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'amount' => $this->paddedAmount,
            'display' => $this->roundedAmount,
            'category' => $this->category,
        ];
    }
}

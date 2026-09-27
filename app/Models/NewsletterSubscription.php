<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $email
 * @property string $status
 * @property string $source
 * @property string $unsubscribe_token
 * @property \Illuminate\Support\Carbon|null $subscribed_at
 * @property \Illuminate\Support\Carbon|null $unsubscribed_at
 * @property string|null $ip
 * @property string|null $user_agent
 */
class NewsletterSubscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * Deterministic unsubscribe URL for one-click unsubscribes from
     * the footer of every newsletter send.
     */
    public function unsubscribeUrl(): string
    {
        return url('/newsletter/unsubscribe/'.$this->unsubscribe_token);
    }

    public static function makeToken(): string
    {
        return Str::random(40);
    }
}

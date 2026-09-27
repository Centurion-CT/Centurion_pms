<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message on a guest_request thread — front-desk operator ↔ guest.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $guest_request_id
 * @property string $sender_type          'operator' | 'guest'
 * @property int|null $sender_operator_id  operator who authored, when sender_type = 'operator'
 * @property string $message
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class GuestRequestMessage extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    public function guestRequest(): BelongsTo
    {
        return $this->belongsTo(GuestRequest::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'sender_operator_id');
    }

    public function isFromOperator(): bool
    {
        return $this->sender_type === 'operator';
    }
}

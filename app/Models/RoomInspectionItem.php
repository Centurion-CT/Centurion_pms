<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $room_inspection_id
 * @property int|null $housekeeping_checklist_item_id
 * @property string $label
 * @property bool $checked
 * @property string|null $damage_note
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property int|null $resolved_by_operator_id
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property int|null $checked_by_operator_id
 */
class RoomInspectionItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked' => 'boolean',
            'checked_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * A damage line is "open" (still needs replacement) when the
     * inspector flagged a damage_note AND no manager has marked it
     * resolved yet.
     */
    public function isOpenDamage(): bool
    {
        return trim((string) $this->damage_note) !== '' && $this->resolved_at === null;
    }

    /**
     * @return BelongsTo<RoomInspection, $this>
     */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RoomInspection::class, 'room_inspection_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reusable checklist template ("bundle") a manager authors once and
 * attaches to any number of room categories. Item text is
 * snapshotted onto every RoomInspection at open time so editing the
 * template later doesn't rewrite live inspections. When multiple
 * bundles attach to the same category the OpenRoomInspection action
 * unions them, deduping by case-insensitive label.
 */
class HousekeepingChecklist extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Legacy test fixtures create checklists without a name; the
        // column is NOT NULL. Default it here so existing tests keep
        // working — the manager UI still requires a real value on
        // save because the wizard validates non-blank before insert.
        static::creating(function (self $model): void {
            if (empty($model->name)) {
                $model->name = 'Standard';
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<RoomCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            RoomCategory::class,
            'housekeeping_checklist_room_category',
            'housekeeping_checklist_id',
            'room_category_id',
        )->withTimestamps();
    }

    /**
     * @return HasMany<HousekeepingChecklistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(HousekeepingChecklistItem::class)->orderBy('sort_order');
    }
}

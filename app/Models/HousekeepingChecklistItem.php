<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HousekeepingChecklistItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<HousekeepingChecklist, $this>
     */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(HousekeepingChecklist::class, 'housekeeping_checklist_id');
    }
}

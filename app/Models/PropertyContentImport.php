<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property string|null $google_url
 * @property string|null $booking_url
 * @property string|null $airbnb_url
 * @property ContentImportStatus $status
 * @property array|null $suggested_content
 * @property array|null $approved_content
 * @property string|null $error_message
 * @property int|null $run_by_admin_id
 * @property int|null $approved_by_admin_id
 * @property Carbon|null $fetched_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $notified_operator_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PropertyContentImport extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'               => ContentImportStatus::class,
            'suggested_content'    => 'array',
            'approved_content'     => 'array',
            'fetched_at'           => 'datetime',
            'approved_at'          => 'datetime',
            'notified_operator_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Admin, $this> */
    public function runByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'run_by_admin_id');
    }

    /** @return BelongsTo<Admin, $this> */
    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by_admin_id');
    }

    /** @return HasMany<AiExtraction, $this> */
    public function extractions(): HasMany
    {
        return $this->hasMany(AiExtraction::class, 'import_id');
    }

    /**
     * Any URL to fetch from? An import row with no URLs is a no-op.
     */
    public function hasAnyUrl(): bool
    {
        return $this->google_url !== null
            || $this->booking_url !== null
            || $this->airbnb_url !== null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic tenant-owned model used to exercise BelongsToTenant + TenantScope
 * without coupling tests to a specific Phase 1 schema (properties, rooms, etc.).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $label
 */
class TenantOwnedFixture extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_owned_fixtures';

    /** @var list<string> */
    protected $guarded = [];

    public $timestamps = false;
}

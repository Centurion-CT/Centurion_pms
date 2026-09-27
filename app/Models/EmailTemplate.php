<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailTemplateKey;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property EmailTemplateKey $key
 * @property string $subject
 * @property string $body
 * @property bool $active
 * @property int|null $updated_by_operator_id
 */
class EmailTemplate extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => EmailTemplateKey::class,
            'active' => 'bool',
        ];
    }
}

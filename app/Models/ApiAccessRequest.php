<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $company
 * @property string|null $github_url
 * @property string|null $use_case
 * @property string|null $integrations
 * @property string $status
 * @property string|null $admin_notes
 * @property string|null $ip
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ApiAccessRequest extends Model
{
    protected $guarded = ['id'];
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $phone
 * @property string $property_name
 * @property string|null $city
 * @property string|null $property_size
 * @property string|null $preferred_window
 * @property string|null $notes
 * @property string $status
 * @property string $source
 * @property string|null $ip
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class DemoRequest extends Model
{
    protected $guarded = ['id'];
}

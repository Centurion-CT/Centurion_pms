<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Operator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Operator
 */
class OperatorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role?->value,
            'role_label' => $this->role?->label(),
            'permissions' => (array) ($this->permissions ?? []),
            'email_verified' => $this->email_verified_at !== null,
        ];
    }
}

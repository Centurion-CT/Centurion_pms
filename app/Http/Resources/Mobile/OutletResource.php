<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Outlet
 */
class OutletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type?->value,
            'active' => (bool) $this->active,
            'tax_rate' => (string) $this->tax_rate,
        ];
    }
}

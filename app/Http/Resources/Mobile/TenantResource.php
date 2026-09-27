<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $property = $this->property;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'property' => $property ? [
                'id' => $property->id,
                'type' => $property->type?->value,
                'stock_model' => $property->stock_model?->value,
            ] : null,
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\CustomerOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerOrder */
class CustomerOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            ...$this->toStandardApi(),
            'updated_at' => $this->updated_at,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PartLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PartLine
 */
class PartLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'serial' => $this->serial,
        ];
    }
}

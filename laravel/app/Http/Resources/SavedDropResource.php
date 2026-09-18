<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SavedDrop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavedDrop
 */
class SavedDropResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'address' => $this->address,
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
        ];
    }
}

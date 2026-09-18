<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PickupSite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PickupSite
 */
class PickupSiteResource extends JsonResource
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
            'contact_phone' => $this->contact_phone,
        ];
    }
}

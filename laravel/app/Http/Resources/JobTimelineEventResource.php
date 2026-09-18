<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JobTimelineEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobTimelineEvent
 */
class JobTimelineEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'actor_role' => $this->actor_role,
            'agent' => $this->agent?->value,
            'description' => $this->description,
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

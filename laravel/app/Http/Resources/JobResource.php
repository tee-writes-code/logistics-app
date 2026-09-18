<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AgentAskStatus;
use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Job
 */
class JobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'window' => $this->window->value,
            'assigned_rider_id' => $this->assigned_rider_id,
            'queue_position' => $this->queue_position,
            'is_active_for_rider' => $this->is_active_for_rider,
            'is_terminal' => $this->isTerminal(),
            'pickup_site_id' => $this->pickup_site_id,
            'drop_address' => $this->drop_address,
            'drop_contact_name' => $this->drop_contact_name,
            'drop_contact_phone' => $this->drop_contact_phone,
            'notes' => $this->notes,
            'instructions' => $this->instructions,
            'eta_at' => $this->eta_at?->toIso8601String(),
            'booked_at' => $this->booked_at?->toIso8601String(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'picked_up_at' => $this->picked_up_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'can_edit_drop' => $this->isEditableDropStage(),
            'can_edit_notes' => $this->isNotesEditable(),
            'can_cancel' => $this->isCancelable(),
            'has_pod' => $this->when(
                $this->relationLoaded('pod'),
                fn (): bool => $this->pod !== null,
            ),
            'fail_reason' => $this->when(
                $this->relationLoaded('failRecords'),
                fn (): ?string => $this->failRecords->last()?->reason?->value,
            ),
            'customer_id' => $this->customer_id,
            'assigned_rider' => $this->when(
                $this->relationLoaded('assignedRider'),
                fn (): ?array => $this->assignedRider === null ? null : [
                    'id' => $this->assignedRider->id,
                    'name' => $this->assignedRider->name,
                ],
            ),
            'customer' => $this->when(
                $this->relationLoaded('customer'),
                fn (): ?array => $this->customer === null ? null : [
                    'id' => $this->customer->id,
                    'name' => $this->customer->name,
                ],
            ),
            'pending_asks' => $this->when(
                $this->relationLoaded('agentAsks'),
                fn (): array => $this->agentAsks
                    ->where('status', AgentAskStatus::Pending)
                    ->map(fn ($ask): array => [
                        'id' => $ask->id,
                        'type' => $ask->type->value,
                        'agent' => $ask->agent->value,
                    ])->values()->all(),
            ),
            'pickup_site' => PickupSiteResource::make($this->whenLoaded('pickupSite')),
            'part_line' => PartLineResource::make($this->whenLoaded('partLine')),
            'timeline' => JobTimelineEventResource::collection($this->whenLoaded('timelineEvents')),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\RecipientAction;

/**
 * Builds the read model the recipient magic-link view renders: the job's public
 * facts, which recipient actions are available in the current status window, the
 * current hold state, and (when in the live-map window) the mock position.
 *
 * Shared by the recipient show endpoint and every recipient action endpoint so
 * they all return one consistent shape.
 */
class RecipientViewService
{
    public function __construct(private readonly MockMapService $map) {}

    /**
     * The read-only payload for an invalid/expired/revoked/terminal token.
     *
     * @return array<string, mixed>
     */
    public function expired(): array
    {
        return ['valid' => false];
    }

    /**
     * The full recipient view for a live job.
     *
     * @return array<string, mixed>
     */
    public function present(Job $job, string $token): array
    {
        $job->loadMissing(['partLine', 'recipientActions']);

        $status = $job->status;
        // resolveByToken already guarantees the job is non-terminal here.
        $beforeOnSite = $status !== JobStatus::OnSite;
        $onSite = $status === JobStatus::OnSite;

        $activeHold = $job->recipientActions
            ->where('type', 'hold')
            ->first(fn (RecipientAction $action): bool => ($action->meta['released_at'] ?? null) === null);

        $latestInstruction = $job->recipientActions
            ->where('type', 'delivery_instruction')
            ->sortByDesc('id')
            ->first();

        return [
            'valid' => true,
            'token' => $token,
            'job' => [
                'id' => $job->id,
                'status' => $status->value,
                'is_terminal' => $job->isTerminal(),
                'eta_at' => $job->eta_at?->toIso8601String(),
                'picked_up_at' => $job->picked_up_at?->toIso8601String(),
                'drop_address' => $job->drop_address,
                'drop_contact_name' => $job->drop_contact_name,
                'part_line' => $job->partLine !== null ? [
                    'name' => $job->partLine->name,
                    'sku' => $job->partLine->sku,
                    'quantity' => $job->partLine->quantity,
                    'serial' => $job->partLine->serial,
                ] : null,
            ],
            'can' => [
                'instruct' => $beforeOnSite,
                'hold' => $beforeOnSite,
                'receive_confirm' => $onSite,
                'refuse' => $onSite,
            ],
            'hold' => [
                'active' => $activeHold !== null,
                'flag_miss_hours' => (bool) ($activeHold->meta['flag_miss_hours'] ?? false),
            ],
            'instruction' => $latestInstruction?->note,
            'map_eligible' => in_array($status, JobStatus::liveMapStatuses(), true) && ! $job->isTerminal(),
            'position' => $this->map->positionFor($job),
        ];
    }
}

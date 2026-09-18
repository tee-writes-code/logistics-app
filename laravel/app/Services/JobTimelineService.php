<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Job;
use App\Models\JobTimelineEvent;

/**
 * Writes to the append-only job timeline. Every create/edit/cancel (and, in
 * later iterations, every status change and agent action) records one row here.
 */
class JobTimelineService
{
    /**
     * Append a timeline event to a job.
     *
     * @param  array<string, mixed>  $meta
     */
    public function record(
        Job $job,
        string $type,
        ?string $actorRole = null,
        array $meta = [],
        ?string $description = null,
    ): JobTimelineEvent {
        return $job->timelineEvents()->create([
            'type' => $type,
            'actor_role' => $actorRole,
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}

<?php

namespace App\Models;

use App\Enums\AgentType;
use Database\Factories\JobTimelineEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only timeline event. Only created_at is managed; rows are not updated.
 */
#[Fillable(['job_id', 'type', 'actor_role', 'agent', 'description', 'meta'])]
class JobTimelineEvent extends Model
{
    /** @use HasFactory<JobTimelineEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agent' => AgentType::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Job, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}

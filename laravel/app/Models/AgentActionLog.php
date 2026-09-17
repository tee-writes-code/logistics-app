<?php

namespace App\Models;

use App\Enums\AgentType;
use Database\Factories\AgentActionLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only agent activity log. Only created_at is managed.
 */
#[Fillable(['job_id', 'agent', 'goal', 'last_action', 'pending_ask', 'meta'])]
class AgentActionLog extends Model
{
    /** @use HasFactory<AgentActionLogFactory> */
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

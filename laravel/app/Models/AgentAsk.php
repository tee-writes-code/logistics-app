<?php

namespace App\Models;

use App\Enums\AgentAskStatus;
use App\Enums\AgentAskType;
use App\Enums\AgentType;
use Database\Factories\AgentAskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A confirmation request raised by an agent. Pending asks block the chosen plan
 * until Ops or the owning customer confirms or rejects them (first write wins).
 */
#[Fillable(['job_id', 'agent', 'type', 'status', 'payload', 'resolved_by', 'resolved_at'])]
class AgentAsk extends Model
{
    /** @use HasFactory<AgentAskFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agent' => AgentType::class,
            'type' => AgentAskType::class,
            'status' => AgentAskStatus::class,
            'payload' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === AgentAskStatus::Pending;
    }

    /**
     * The subset of the given job ids that are paused by a pending `unsafe` ask.
     * The pending ask itself is the pause signal — there is no pause column — so
     * this is the shared source of truth for "which of these jobs are paused",
     * used by both the Dispatch queue rebuild and the terminal-transition promote.
     *
     * @param  array<int, int>  $jobIds
     * @return Collection<int, int>
     */
    public static function pendingUnsafeJobIds(array $jobIds): Collection
    {
        if ($jobIds === []) {
            return collect();
        }

        return static::query()
            ->where('type', AgentAskType::Unsafe)
            ->where('status', AgentAskStatus::Pending)
            ->whereIn('job_id', $jobIds)
            ->pluck('job_id');
    }

    /**
     * @return BelongsTo<Job, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

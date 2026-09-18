<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AgentType;
use App\Models\AgentActionLog;
use App\Models\Job;

/**
 * The single writer of the append-only agent_action_logs table. Every agent
 * action records exactly one row here so the Ops workbench can surface each
 * agent's goal, its last action, and any pending ask.
 */
class AgentLogService
{
    /**
     * Append one agent activity row.
     *
     * @param  array<string, mixed>  $meta
     */
    public function log(
        AgentType $agent,
        string $goal,
        ?string $lastAction = null,
        ?Job $job = null,
        ?string $pendingAsk = null,
        array $meta = [],
    ): AgentActionLog {
        return AgentActionLog::query()->create([
            'job_id' => $job?->id,
            'agent' => $agent,
            'goal' => $goal,
            'last_action' => $lastAction,
            'pending_ask' => $pendingAsk,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}

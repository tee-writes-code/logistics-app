<?php

declare(strict_types=1);

namespace App\Agents;

use App\Agents\Templates\CustomerOpsTemplates;
use App\Enums\AgentType;
use App\Enums\JobStatus;
use App\Models\Job;
use App\Services\AgentLogService;
use App\Services\JobTimelineService;
use App\Services\NotificationService;

/**
 * The Customer-ops agent: deterministic, templated communication. It never
 * changes assignment or status — it only fans notifications out through the
 * single NotificationService writer with agent-authored copy, posts plan-change
 * timeline notes, and answers status questions from current job state.
 *
 * Every event it handles is one the JobTransitioned listener does NOT itself
 * emit (or is routed through this agent by that listener), so there is always
 * exactly one notification set per event — no double-notify.
 */
class CustomerOpsAgent
{
    /**
     * Events that represent a change of plan (worth a timeline note), as opposed
     * to a routine step in the run.
     *
     * @var array<int, string>
     */
    private const PLAN_CHANGES = ['assigned', 'reassigned', 'reattempt', 'next', 'return', 'unsafe'];

    /**
     * Default notification audiences for an event.
     *
     * @var array<int, string>
     */
    private const DEFAULT_AUDIENCES = ['customer', 'rider', 'ops', 'recipient'];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly JobTimelineService $timeline,
        private readonly AgentLogService $log,
    ) {}

    /**
     * Fan a templated notification set out for a job event and log it. On a plan
     * change it also posts a plan-change timeline note.
     */
    public function notify(Job $job, string $event): void
    {
        $this->notifications->emit(
            $job,
            $event,
            self::DEFAULT_AUDIENCES,
            static fn (string $e, string $audience, Job $j): string => CustomerOpsTemplates::notification($e, $audience, $j),
        );

        if (in_array($event, self::PLAN_CHANGES, true)) {
            $this->postPlanNote($job, $event);
        }

        $this->log->log(
            AgentType::CustomerOps,
            'Keep the customer and recipient informed.',
            "Sent {$event} notification set.",
            $job,
            null,
            ['event' => $event],
        );
    }

    /**
     * Post a plan-change timeline note without sending a notification. Used when
     * the notification for the change is already carried by another event (e.g. a
     * `next` reschedule whose reassignment emits its own `assigned` notice).
     *
     * @param  array<string, mixed>  $meta
     */
    public function planNote(Job $job, string $event, array $meta = []): void
    {
        $this->postPlanNote($job, $event, $meta);

        $this->log->log(
            AgentType::CustomerOps,
            'Record the plan change on the timeline.',
            CustomerOpsTemplates::planNote($event, $job),
            $job,
            null,
            array_merge(['event' => $event], $meta),
        );
    }

    /**
     * A templated, plain-language status line for the job's current state. Never
     * invents a live location before the part is picked up.
     */
    public function answerStatus(Job $job): string
    {
        $line = CustomerOpsTemplates::status($job);

        $this->log->log(
            AgentType::CustomerOps,
            'Answer a status question from current state.',
            $line,
            $job,
            null,
            ['status' => $job->status->value],
        );

        return $line;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function postPlanNote(Job $job, string $event, array $meta = []): void
    {
        $this->timeline->record(
            $job,
            'plan_change',
            'agent',
            array_merge(['event' => $event, 'agent' => AgentType::CustomerOps->value], $meta),
            CustomerOpsTemplates::planNote($event, $job),
        );
    }

    /**
     * Whether the job is past the point where a live location may be quoted.
     */
    public function hasLiveLocation(Job $job): bool
    {
        return in_array($job->status, JobStatus::liveMapStatuses(), true);
    }
}

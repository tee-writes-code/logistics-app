<?php

declare(strict_types=1);

namespace App\Agents;

use App\Enums\AgentType;
use App\Enums\JobStatus;
use App\Models\AgentAsk;
use App\Models\Job;
use App\Models\User;
use App\Services\AgentLogService;
use App\Services\ClockService;
use App\Services\JobStatusService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Dispatch agent: deterministic assignment and scheduling. It picks the
 * least-loaded rider, sets a deterministic ETA from the clock plus fixed leg
 * minutes, and keeps each rider's queue ordered with exactly one active job.
 *
 * Status writes go through JobStatusService::transition(); the assignment fields
 * (assigned_rider_id, queue_position, is_active_for_rider, eta_at) are owned here
 * and written directly, coordinated around each transition.
 */
class DispatchAgent
{
    public function __construct(
        private readonly JobStatusService $status,
        private readonly ClockService $clock,
        private readonly AgentLogService $log,
        private readonly CustomerOpsAgent $customerOps,
    ) {}

    /**
     * React to a freshly booked job by assigning a rider, scheduling it, and
     * moving it to `assigned`. With no rider available the job stays booked and
     * the constraint is logged. No confirmation is required.
     */
    public function onBooked(Job $job): Job
    {
        $rider = $this->pickRider();

        if ($rider === null) {
            $this->log->log(
                AgentType::Dispatch,
                'Assign the least-loaded rider and schedule the run.',
                'No assignable rider available; job stays booked.',
                $job,
                null,
                ['constraint' => 'no_assignable_rider'],
            );

            return $job;
        }

        $this->enqueue($job, $rider);
        $job->assigned_at = $this->clock->now();
        $job->save();

        $this->status->transition($job, JobStatus::Assigned, 'dispatch', ['rider_id' => $rider->id]);
        $this->recompute($rider);

        $job->refresh();

        $this->log->log(
            AgentType::Dispatch,
            'Assign the least-loaded rider and schedule the run.',
            "Assigned to {$rider->name} (queue position {$job->queue_position}).",
            $job,
            null,
            ['rider_id' => $rider->id, 'eta_at' => $job->eta_at?->toIso8601String()],
        );

        return $job;
    }

    /**
     * Manually assign (Ops override) a job to a rider, rebuilding both the new and
     * old rider queues. A still-booked job is moved to `assigned`; an already
     * assigned job is reassigned with a plan-change notice. No confirmation.
     */
    public function assign(Job $job, User $rider, string $actorRole = 'ops'): Job
    {
        $oldRiderId = $job->assigned_rider_id;
        $wasBooked = $job->status === JobStatus::Booked;

        $this->enqueue($job, $rider);
        $job->assigned_at ??= $this->clock->now();
        $job->save();

        if ($wasBooked) {
            $this->status->transition($job, JobStatus::Assigned, $actorRole, ['rider_id' => $rider->id]);
        }

        $this->recompute($rider);

        if ($oldRiderId !== null && $oldRiderId !== $rider->id) {
            $oldRider = User::query()->find($oldRiderId);
            if ($oldRider !== null) {
                $this->recompute($oldRider);
            }
        }

        $job->refresh();

        if (! $wasBooked) {
            // No status transition fired, so emit the plan-change notice directly.
            $this->customerOps->notify($job, 'reassigned');
        }

        $this->log->log(
            AgentType::Dispatch,
            'Apply the Ops assignment override.',
            "Assigned to {$rider->name} (queue position {$job->queue_position}).",
            $job,
            null,
            ['rider_id' => $rider->id, 'previous_rider_id' => $oldRiderId, 'override' => true],
        );

        return $job;
    }

    /**
     * Apply an explicit queue order for a rider. The active/in-flight job must
     * stay at the head; any order that moves it is rejected.
     *
     * @param  array<int, int>  $orderedJobIds
     */
    public function reorder(User $rider, array $orderedJobIds): void
    {
        DB::transaction(function () use ($rider, $orderedJobIds): void {
            $jobs = $this->liveQueue($rider)->keyBy('id');

            if ($jobs->keys()->sort()->values()->all() !== collect($orderedJobIds)->sort()->values()->all()) {
                throw new InvalidArgumentException('The order must list exactly the rider\'s live jobs.');
            }

            $inFlight = $jobs->first(fn (Job $job): bool => $this->isInFlight($job));

            if ($inFlight !== null && ($orderedJobIds[0] ?? null) !== $inFlight->id) {
                throw new InvalidArgumentException('The active job must stay at the front of the queue.');
            }

            foreach ($orderedJobIds as $position => $jobId) {
                /** @var Job $job */
                $job = $jobs->get($jobId);
                $job->queue_position = $position;
                $job->is_active_for_rider = $position === 0;
                if (in_array($job->status, [JobStatus::Booked, JobStatus::Assigned], true)) {
                    $this->setEta($job);
                }
                $job->save();
            }
        });

        $this->log->log(
            AgentType::Dispatch,
            'Reorder the rider queue.',
            "Reordered {$rider->name}'s queue.",
            null,
            null,
            ['rider_id' => $rider->id, 'order' => array_values($orderedJobIds)],
        );
    }

    /**
     * Rebuild a rider's queue: stable order with the in-flight job first, exactly
     * one active job at position 0, and fresh ETAs for not-yet-started jobs. Skips
     * terminal jobs (they carry no queue position).
     */
    public function recompute(User $rider): void
    {
        DB::transaction(function () use ($rider): void {
            $live = $this->liveQueue($rider);
            $paused = $this->pausedJobIds($live);

            // Each closure is a two-arg comparator: Collection::sortByMany invokes
            // callable keys as `$prop($a, $b)`, so it must return a `<=>` result,
            // not a rank of `$a` alone (a single-arg key would sort incorrectly).
            $ordered = $live
                ->sortBy([
                    // Paused (pending-unsafe) jobs sink below everything so they
                    // never take the active slot; otherwise a started job leads so
                    // the active job stays at the head.
                    fn (Job $a, Job $b): int => $this->queueRank($a, $paused) <=> $this->queueRank($b, $paused),
                    fn (Job $a, Job $b): int => ($a->queue_position ?? PHP_INT_MAX) <=> ($b->queue_position ?? PHP_INT_MAX),
                    fn (Job $a, Job $b): int => $a->id <=> $b->id,
                ])
                ->values();

            foreach ($ordered as $position => $job) {
                $job->queue_position = $position;
                // A paused job never becomes active, even at position 0, so an
                // unsafe pause survives recompute until the ask is resolved.
                $job->is_active_for_rider = $position === 0 && ! $paused->contains($job->id);
                if (in_array($job->status, [JobStatus::Booked, JobStatus::Assigned], true)) {
                    $this->setEta($job);
                }
                $job->save();
            }
        });
    }

    /**
     * The ordering rank of a job within its rider's queue: paused (pending-unsafe)
     * jobs sink last (2), in-flight jobs lead (0), everything else sits between (1).
     *
     * @param  Collection<int, int>  $paused
     */
    private function queueRank(Job $job, Collection $paused): int
    {
        if ($paused->contains($job->id)) {
            return 2;
        }

        return $this->isInFlight($job) ? 0 : 1;
    }

    /**
     * The ids of the given jobs that are paused by a pending `unsafe` AgentAsk.
     * The pending ask itself is the pause signal — there is no pause column.
     *
     * @param  Collection<int, Job>  $jobs
     * @return Collection<int, int>
     */
    private function pausedJobIds(Collection $jobs): Collection
    {
        return AgentAsk::pendingUnsafeJobIds($jobs->pluck('id')->all());
    }

    /**
     * The least-loaded rider (fewest live jobs), ties broken by lowest id. An
     * optional rider is excluded (used when reassigning off a rider).
     */
    public function pickRider(?int $excludeRiderId = null): ?User
    {
        $terminal = $this->terminalValues();

        $riders = User::riders()
            ->when($excludeRiderId !== null, fn ($query) => $query->whereKeyNot($excludeRiderId))
            ->withCount(['assignedJobs as live_jobs_count' => fn ($query) => $query->whereNotIn('status', $terminal)])
            ->orderBy('id')
            ->get();

        // Two-arg comparators: sortByMany calls each closure as `$prop($a, $b)`
        // and expects a `<=>` result, so return comparisons, not single ranks.
        return $riders
            ->sortBy([
                fn (User $a, User $b): int => (int) $a->live_jobs_count <=> (int) $b->live_jobs_count,
                fn (User $a, User $b): int => $a->id <=> $b->id,
            ])
            ->first();
    }

    /**
     * Append a job to a rider's queue and set its deterministic ETA.
     */
    public function enqueue(Job $job, User $rider): void
    {
        $maxPosition = Job::query()
            ->where('assigned_rider_id', $rider->id)
            ->whereNotIn('status', $this->terminalValues())
            ->max('queue_position');

        $job->assigned_rider_id = $rider->id;
        $job->queue_position = $maxPosition === null ? 0 : ((int) $maxPosition + 1);
        $this->setEta($job);
        $job->save();
    }

    /**
     * Set a job's ETA to now plus the fixed leg minutes, scaled by its position in
     * the queue so later jobs finish later.
     */
    public function setEta(Job $job): void
    {
        $position = $job->queue_position ?? 0;
        $job->eta_at = $this->clock->now()->addMinutes(($position + 1) * $this->totalLegMinutes());
    }

    /**
     * A rider's live (non-terminal) assigned jobs.
     *
     * @return Collection<int, Job>
     */
    private function liveQueue(User $rider): Collection
    {
        return Job::query()
            ->where('assigned_rider_id', $rider->id)
            ->whereNotIn('status', $this->terminalValues())
            ->get();
    }

    /**
     * Whether the job has started moving (past `assigned`) but is not terminal.
     */
    private function isInFlight(Job $job): bool
    {
        return ! in_array($job->status, [JobStatus::Booked, JobStatus::Assigned], true)
            && ! $job->status->isTerminal();
    }

    private function totalLegMinutes(): int
    {
        $legs = config('logistics.leg_minutes', []);

        return (int) array_sum(is_array($legs) ? $legs : []);
    }

    /**
     * @return array<int, string>
     */
    private function terminalValues(): array
    {
        return array_map(static fn (JobStatus $status): string => $status->value, JobStatus::terminalStatuses());
    }
}

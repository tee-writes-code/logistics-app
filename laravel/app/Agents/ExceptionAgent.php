<?php

declare(strict_types=1);

namespace App\Agents;

use App\Enums\AgentAskStatus;
use App\Enums\AgentAskType;
use App\Enums\AgentType;
use App\Enums\JobStatus;
use App\Enums\JobWindow;
use App\Exceptions\AgentAskException;
use App\Models\AgentAsk;
use App\Models\Job;
use App\Models\User;
use App\Services\AgentLogService;
use App\Services\ClockService;
use App\Services\JobStatusService;
use App\Services\SchedulingService;
use Illuminate\Support\Facades\DB;

/**
 * The Exception agent: deterministic exception handling. It reassigns around
 * delays, plans same-day reattempts when hours allow, and raises AgentAsks (that
 * block until Ops/Customer confirm) when a plan needs a human decision. It hands
 * customer-facing copy to the Customer-ops agent and never composes free text.
 */
class ExceptionAgent
{
    private const GOAL = 'Resolve the delivery exception with the least disruption.';

    public function __construct(
        private readonly JobStatusService $status,
        private readonly SchedulingService $scheduling,
        private readonly ClockService $clock,
        private readonly DispatchAgent $dispatch,
        private readonly CustomerOpsAgent $customerOps,
        private readonly AgentLogService $log,
    ) {}

    /**
     * A rider is delayed: reassign the job to another rider and rebuild both
     * queues. No confirmation. A no-op (logged) when no alternate rider exists.
     */
    public function onDelay(Job $job): void
    {
        $newRider = $this->dispatch->pickRider($job->assigned_rider_id);

        if ($newRider === null) {
            $this->log->log(
                AgentType::Exception,
                self::GOAL,
                'No alternate rider available; kept the current assignment.',
                $job,
                null,
                ['constraint' => 'no_alternate_rider'],
            );

            return;
        }

        // A delay hands the run to a fresh rider, so any progress the delayed rider
        // made is rolled back: an in-flight job is reset to `assigned` (clearing the
        // progressed status and its pickup timestamp) before the new rider takes it,
        // otherwise the new rider would inherit a step they never performed and
        // recompute() would pin the job as in-flight. The reset is silent — the
        // single customer-facing 'reassigned' notice is emitted by assign() below.
        // Guarded to the moving statuses that carry an `assigned` back-edge (a
        // failed job has none and is reassigned as-is).
        if ($this->isInFlight($job) && in_array(JobStatus::Assigned, $this->status->allowedTransitions($job->status), true)) {
            $this->status->transition($job, JobStatus::Assigned, 'exception', [
                'plan' => 'delay_reset',
                'silent' => true,
            ]);
            $job->refresh();
        }

        $this->dispatch->assign($job, $newRider, 'exception');

        $this->log->log(
            AgentType::Exception,
            self::GOAL,
            "Reassigned the delayed job to {$newRider->name}.",
            $job->refresh(),
            null,
            ['new_rider_id' => $newRider->id],
        );
    }

    /**
     * A rider-reported failure. Reattempt same-day if hours fit; otherwise ask to
     * reschedule to the next window.
     */
    public function onFail(Job $job): void
    {
        $this->handleFailure($job, 'fail', AgentAskType::Next);
    }

    /**
     * A recipient refusal. Reattempt same-day if hours fit; otherwise ask to
     * return the part to the shop.
     */
    public function onRefuse(Job $job): void
    {
        $this->handleFailure($job, 'refuse', AgentAskType::Return);
    }

    /**
     * A hold that would push the job past today's hours: ask to reschedule to the
     * next window (Ops or Customer confirm).
     */
    public function onHoldMissesHours(Job $job): void
    {
        $ask = $this->createAsk($job, AgentAskType::Next, 'hold');

        $this->log->log(
            AgentType::Exception,
            self::GOAL,
            'A hold misses today\'s hours; proposed rescheduling to the next window.',
            $job,
            'Awaiting confirmation to reschedule to the next window.',
            ['origin' => 'hold', 'ask_id' => $ask->id],
        );
    }

    /**
     * An unsafe situation: pause the job (rider stops) and raise an Ops-confirm
     * ask. Resuming is handled on confirm.
     */
    public function onUnsafe(Job $job): void
    {
        $wasActive = $job->is_active_for_rider;
        $job->is_active_for_rider = false;
        $job->save();

        $ask = $this->createAsk($job, AgentAskType::Unsafe, 'unsafe', [
            'was_active' => $wasActive,
            'rider_id' => $job->assigned_rider_id,
        ]);

        $this->customerOps->notify($job->refresh(), 'unsafe');

        // The pending unsafe ask now excludes this job from the active slot, so
        // recompute lets the rider's next live, non-paused job take over. The
        // paused job stays paused (pausedJobIds honours the pending ask) while the
        // rider is not left with zero active jobs.
        if ($job->assigned_rider_id !== null) {
            $rider = User::query()->find($job->assigned_rider_id);
            if ($rider !== null) {
                $this->dispatch->recompute($rider);
            }
        }

        $this->log->log(
            AgentType::Exception,
            self::GOAL,
            'Paused the job pending an Ops safety review.',
            $job->refresh(),
            'Awaiting Ops confirmation that it is safe to resume.',
            ['origin' => 'unsafe', 'ask_id' => $ask->id],
        );
    }

    /**
     * Confirm a pending ask: re-check feasibility, execute the chosen plan, and
     * resolve the ask. First write wins; a second resolution returns a 409.
     */
    public function confirm(AgentAsk $ask, User $resolver, ?string $decision = null): AgentAsk
    {
        return DB::transaction(function () use ($ask, $resolver, $decision): AgentAsk {
            /** @var AgentAsk $fresh */
            $fresh = AgentAsk::query()->whereKey($ask->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isPending()) {
                throw AgentAskException::alreadyResolved();
            }

            /** @var Job $job */
            $job = $fresh->job()->firstOrFail();
            $origin = (string) ($fresh->payload['origin'] ?? 'fail');

            // Resolve the ask BEFORE executing the plan. Everything runs in this one
            // transaction, so a plan that throws (e.g. a reattempt that no longer
            // fits) rolls the resolution back and the ask stays pending. Resolving
            // first also means an `unsafe` resume no longer sees its own pending ask,
            // so recompute() can restore the job's active slot.
            $fresh->status = AgentAskStatus::Confirmed;
            $fresh->resolved_by = $resolver->id;
            $fresh->resolved_at = $this->clock->now();
            $fresh->save();

            match ($origin) {
                'fail', 'refuse' => $this->executeFailureDecision($fresh, $job, $decision),
                'hold' => $this->executeNextWindow($job),
                'unsafe' => $this->resumeFromUnsafe($fresh, $job),
                default => throw AgentAskException::invalidDecision(),
            };

            $this->log->log(
                AgentType::Exception,
                self::GOAL,
                "Confirmed the {$fresh->type->value} plan.",
                $job->refresh(),
                null,
                ['ask_id' => $fresh->id, 'origin' => $origin, 'resolved_by' => $resolver->id],
            );

            return $fresh;
        });
    }

    /**
     * Reject a pending ask.
     *
     * - A miss-hours (fail/refuse) proposal is left open so a different decision
     *   can still be made.
     * - An `unsafe` ask is kept paused: the ask stays pending (it is the pause
     *   signal), so the job never silently resumes on a later recompute. Only a
     *   confirm resumes it. Recompute keeps a sibling live job in the active slot.
     * - Any other ask (hold) is resolved as rejected.
     */
    public function reject(AgentAsk $ask, User $resolver): AgentAsk
    {
        return DB::transaction(function () use ($ask, $resolver): AgentAsk {
            /** @var AgentAsk $fresh */
            $fresh = AgentAsk::query()->whereKey($ask->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isPending()) {
                throw AgentAskException::alreadyResolved();
            }

            /** @var Job $job */
            $job = $fresh->job()->firstOrFail();
            $origin = (string) ($fresh->payload['origin'] ?? 'fail');
            $missHoursOpen = in_array($origin, ['fail', 'refuse'], true);
            $keptPaused = $origin === 'unsafe';
            $leftOpen = $missHoursOpen || $keptPaused;

            if (! $leftOpen) {
                $fresh->status = AgentAskStatus::Rejected;
                $fresh->resolved_by = $resolver->id;
                $fresh->resolved_at = $this->clock->now();
                $fresh->save();
            }

            // Rejecting a safety review keeps the job paused. Recompute so a live,
            // non-paused sibling job holds the active slot and the rider is not
            // stranded with zero active jobs; the paused job stays paused.
            if ($keptPaused && $job->assigned_rider_id !== null) {
                $rider = User::query()->find($job->assigned_rider_id);
                if ($rider !== null) {
                    $this->dispatch->recompute($rider);
                }
            }

            $this->log->log(
                AgentType::Exception,
                self::GOAL,
                match (true) {
                    $keptPaused => "Rejected the {$fresh->type->value} review; the job stays paused until it is confirmed safe.",
                    $missHoursOpen => "Rejected the {$fresh->type->value} plan; the request stays open for another decision.",
                    default => "Rejected the {$fresh->type->value} request.",
                },
                $job->refresh(),
                match (true) {
                    $keptPaused => 'Still paused pending an Ops safety confirmation.',
                    $missHoursOpen => 'Still awaiting a decision.',
                    default => null,
                },
                ['ask_id' => $fresh->id, 'origin' => $origin, 'left_open' => $leftOpen, 'kept_paused' => $keptPaused],
            );

            return $fresh;
        });
    }

    private function handleFailure(Job $job, string $origin, AgentAskType $missType): void
    {
        if ($this->scheduling->canFinishSameDay($job)) {
            $this->status->transition($job, JobStatus::EnRouteDrop, 'exception', ['origin' => $origin]);
            $this->customerOps->notify($job->refresh(), 'reattempt');

            $this->log->log(
                AgentType::Exception,
                self::GOAL,
                'Hours still fit; scheduled a same-day reattempt.',
                $job,
                null,
                ['origin' => $origin, 'plan' => 'reattempt'],
            );

            return;
        }

        $ask = $this->createAsk($job, $missType, $origin);

        $this->log->log(
            AgentType::Exception,
            self::GOAL,
            "Hours do not fit; proposed a {$missType->value} plan.",
            $job,
            "Awaiting confirmation to {$missType->value}.",
            ['origin' => $origin, 'plan' => $missType->value, 'ask_id' => $ask->id],
        );
    }

    private function executeFailureDecision(AgentAsk $ask, Job $job, ?string $decision): void
    {
        $decision ??= $this->defaultDecision($ask, $job);

        match ($decision) {
            'reattempt' => $this->executeReattempt($job),
            'next' => $this->executeNextWindow($job),
            'return' => $this->executeReturn($job),
            default => throw AgentAskException::invalidDecision(),
        };
    }

    private function defaultDecision(AgentAsk $ask, Job $job): string
    {
        if ($ask->type === AgentAskType::Return) {
            return 'return';
        }

        return $this->scheduling->canFinishSameDay($job) ? 'reattempt' : 'next';
    }

    private function executeReattempt(Job $job): void
    {
        if (! $this->scheduling->canFinishSameDay($job)) {
            throw AgentAskException::reattemptNoLongerFits();
        }

        $this->status->transition($job, JobStatus::EnRouteDrop, 'exception', ['plan' => 'reattempt']);
        $this->customerOps->notify($job->refresh(), 'reattempt');
    }

    private function executeNextWindow(Job $job): void
    {
        $oldRiderId = $job->assigned_rider_id;

        // A fail/refuse job sits at `failed` (which re-books directly). A hold-origin
        // reschedule can arrive mid-run, so roll a job that cannot re-book directly
        // back to `assigned` first (silent — the 'next' plan note below is the
        // customer-facing notice). This keeps the only edge into `booked` the
        // deterministic failed->booked / assigned->booked.
        if (! in_array(JobStatus::Booked, $this->status->allowedTransitions($job->status), true)) {
            $this->status->transition($job, JobStatus::Assigned, 'exception', [
                'plan' => 'next_reset',
                'silent' => true,
            ]);
            $job->refresh();
        }

        $this->status->transition($job, JobStatus::Booked, 'exception', ['plan' => 'next']);
        $job->refresh();
        $job->window = JobWindow::Next;
        $job->save();

        $this->dispatch->onBooked($job->refresh());
        $job->refresh();

        // onBooked recomputes only the newly chosen rider. When the job leaves the
        // rider that held it (the failed job stayed active on the old rider through
        // failed->booked), that old rider must be recomputed too, or their next job
        // never becomes active and a queue-position gap is left behind. Mirrors the
        // old-rider recompute in DispatchAgent::assign().
        if ($oldRiderId !== null && $oldRiderId !== $job->assigned_rider_id) {
            $oldRider = User::query()->find($oldRiderId);
            if ($oldRider !== null) {
                $this->dispatch->recompute($oldRider);
            }
        }

        $this->customerOps->planNote($job->refresh(), 'next');
    }

    private function executeReturn(Job $job): void
    {
        $this->status->transition($job, JobStatus::Returning, 'exception', ['plan' => 'return']);
        $this->customerOps->notify($job->refresh(), 'return');
    }

    private function resumeFromUnsafe(AgentAsk $ask, Job $job): void
    {
        // The ask is already resolved (see confirm), so the job is no longer paused.
        // Rebuild the rider's queue: the resumed job retakes its active slot if it
        // leads the queue, and any job promoted to active during the pause steps
        // back. One active job per rider is restored deterministically.
        if ($job->assigned_rider_id !== null) {
            $rider = User::query()->find($job->assigned_rider_id);
            if ($rider !== null) {
                $this->dispatch->recompute($rider);
            }
        }

        $this->customerOps->planNote($job->refresh(), 'reattempt', ['origin' => 'unsafe_resume']);
    }

    /**
     * Whether the job has started moving (past `assigned`) but is not terminal.
     */
    private function isInFlight(Job $job): bool
    {
        return ! in_array($job->status, [JobStatus::Booked, JobStatus::Assigned], true)
            && ! $job->status->isTerminal();
    }

    /**
     * @param  array<string, mixed>  $extraPayload
     */
    private function createAsk(Job $job, AgentAskType $type, string $origin, array $extraPayload = []): AgentAsk
    {
        return AgentAsk::query()->create([
            'job_id' => $job->id,
            'agent' => AgentType::Exception,
            'type' => $type,
            'status' => AgentAskStatus::Pending,
            'payload' => array_merge(['origin' => $origin], $extraPayload),
        ]);
    }
}

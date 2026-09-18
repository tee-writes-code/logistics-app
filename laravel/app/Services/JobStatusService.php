<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FailReason;
use App\Enums\JobStatus;
use App\Events\JobTransitioned;
use App\Exceptions\JobTransitionException;
use App\Models\AgentAsk;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The single writer of jobs.status after a job is created. Every status change
 * — rider actions, customer/ops cancel, and (from iter-4) dispatch/exception
 * moves — flows through transition(), which validates the edge against the
 * allowed-transition map, applies the matching side effects, records a timeline
 * event, and dispatches JobTransitioned, all inside one DB transaction.
 *
 * There are NO free status writes elsewhere in the app.
 */
class JobStatusService
{
    /**
     * Allowed status edges. Keyed by the current status value; the value is the
     * list of statuses the job may move to next. Edges out of `failed` and into
     * `booked`/`assigned` are legal here but are driven by iter-4 (Dispatch +
     * Exception replan); no iter-2 endpoint triggers them.
     *
     * The in-flight -> `assigned` back-edges and `assigned` -> `booked` are
     * agent-driven ONLY: a delay reassignment (ExceptionAgent::onDelay) rolls a
     * progressed job back to `assigned` so the new rider starts fresh, and a
     * next-window reschedule (ExceptionAgent::executeNextWindow) re-books it via
     * `assigned` -> `booked`. No rider/customer/ops HTTP endpoint ever requests
     * `assigned` or `booked`, so exposing these edges here widens no user action.
     *
     * @var array<string, array<int, JobStatus>>
     */
    private const TRANSITIONS = [
        'booked' => [JobStatus::Assigned, JobStatus::Cancelled],
        'assigned' => [JobStatus::EnRoutePickup, JobStatus::Cancelled, JobStatus::Booked],
        'en_route_pickup' => [JobStatus::AtPickup, JobStatus::Assigned],
        'at_pickup' => [JobStatus::PickedUp, JobStatus::Assigned],
        'picked_up' => [JobStatus::EnRouteDrop, JobStatus::Assigned],
        'en_route_drop' => [JobStatus::OnSite, JobStatus::Assigned],
        'on_site' => [JobStatus::Delivered, JobStatus::Failed, JobStatus::Assigned],
        'failed' => [JobStatus::EnRouteDrop, JobStatus::Booked, JobStatus::Returning],
        'returning' => [JobStatus::Returned],
        'delivered' => [],
        'returned' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly ClockService $clock,
        private readonly JobTimelineService $timeline,
    ) {}

    /**
     * The statuses a job may move to from its current status.
     *
     * @return array<int, JobStatus>
     */
    public function allowedTransitions(JobStatus $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }

    /**
     * Move a job to a new status, applying side effects and recording history.
     *
     * @param  array<string, mixed>  $payload  Action inputs (signature, photo,
     *                                         reason, note, return_photo, actor).
     *
     * @throws JobTransitionException on an illegal edge (409).
     */
    public function transition(Job $job, JobStatus $to, string $actorRole, array $payload = []): Job
    {
        /** @var array{0: Job, 1: JobStatus} $result */
        $result = DB::transaction(function () use ($job, $to, $payload, $actorRole): array {
            /** @var Job $fresh */
            $fresh = Job::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();

            $from = $fresh->status;

            if (! in_array($to, $this->allowedTransitions($from), true)) {
                throw new JobTransitionException($from, $to, $this->allowedTransitions($from));
            }

            $fresh->status = $to;
            $this->applySideEffects($fresh, $from, $to, $payload);
            $fresh->save();

            $this->timeline->record(
                $fresh,
                $to->value,
                $actorRole,
                $this->timelineMeta($to, $payload),
                $this->describe($to, $actorRole),
            );

            if ($to->isTerminal() && $fresh->assigned_rider_id !== null) {
                $this->promoteNextInQueue($fresh->assignedRider()->firstOrFail());
            }

            return [$fresh->refresh(), $from];
        });

        [$fresh, $from] = $result;

        // Seam for iter-3 (notification/map listeners) and iter-4 (agents):
        // dispatched only after the transaction commits.
        JobTransitioned::dispatch($fresh, $from, $to, $actorRole, $payload);

        return $fresh;
    }

    /**
     * The rider's single active job (lowest-position non-terminal job flagged
     * active), or null when their queue is empty.
     */
    public function activeJobFor(User $rider): ?Job
    {
        return Job::query()
            ->where('assigned_rider_id', $rider->id)
            ->where('is_active_for_rider', true)
            ->whereNotIn('status', $this->terminalValues())
            ->orderBy('queue_position')
            ->first();
    }

    /**
     * After a job goes terminal, close the gap it left in the rider's queue and
     * hand the single active slot to the new head — unless that head is paused by
     * a pending `unsafe` ask, in which case NO job becomes active. This preserves
     * the unsafe-pause invariant: a paused job never becomes active (and so never
     * drivable) just because a sibling reached a terminal state. A no-op when the
     * rider has no live jobs.
     */
    public function promoteNextInQueue(User $rider): void
    {
        DB::transaction(function () use ($rider): void {
            $live = Job::query()
                ->where('assigned_rider_id', $rider->id)
                ->whereNotIn('status', $this->terminalValues())
                ->orderByRaw('queue_position is null, queue_position asc')
                ->orderBy('id')
                ->get();

            // The pending unsafe ask is the pause signal (no pause column). Paused
            // jobs were already sunk to the tail by DispatchAgent::recompute, so
            // renumbering in queue order keeps a non-paused job at the head.
            $paused = AgentAsk::pendingUnsafeJobIds($live->pluck('id')->all());

            foreach ($live->values() as $position => $job) {
                $job->update([
                    'queue_position' => $position,
                    'is_active_for_rider' => $position === 0 && ! $paused->contains($job->id),
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applySideEffects(Job $job, JobStatus $from, JobStatus $to, array $payload): void
    {
        match ($to) {
            JobStatus::Assigned => $this->resetProgressForReassign($job, $from),
            JobStatus::PickedUp => $job->picked_up_at = $this->clock->now(),
            JobStatus::Delivered => $this->recordDelivery($job, $payload),
            JobStatus::Failed => $this->recordFailure($job, $payload),
            JobStatus::Returned => $this->recordReturn($job, $payload),
            JobStatus::Cancelled => $job->cancelled_at = $this->clock->now(),
            default => null,
        };

        if ($to->isTerminal()) {
            $job->is_active_for_rider = false;
            $job->queue_position = null;
        }
    }

    /**
     * A delay reassignment rolls a progressed job back to `assigned` so the new
     * rider starts the run fresh. Clear the progress timestamp the delayed rider
     * set so the run reads as not-yet-collected again. A normal booked->assigned
     * (from Dispatch) has no progress to clear, so this is a no-op there.
     */
    private function resetProgressForReassign(Job $job, JobStatus $from): void
    {
        if ($from === JobStatus::Booked) {
            return;
        }

        $job->picked_up_at = null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordDelivery(Job $job, array $payload): void
    {
        $signature = $payload['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            throw new InvalidArgumentException('A signature is required to mark a job delivered.');
        }

        $job->delivered_at = $this->clock->now();

        $job->pod()->create([
            'signature_path' => $this->storeDataUrl($signature, 'pod', $job->id, 'signature'),
            'photo_path' => isset($payload['photo']) && is_string($payload['photo']) && $payload['photo'] !== ''
                ? $this->storeDataUrl($payload['photo'], 'pod', $job->id, 'photo')
                : null,
            'delivered_by' => $payload['actor_id'] ?? $job->assigned_rider_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordFailure(Job $job, array $payload): void
    {
        $job->failRecords()->create([
            'reason' => $payload['reason'],
            'note' => $payload['note'] ?? null,
            'recorded_by' => $payload['actor_id'] ?? $job->assigned_rider_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordReturn(Job $job, array $payload): void
    {
        if (isset($payload['return_photo']) && is_string($payload['return_photo']) && $payload['return_photo'] !== '') {
            $job->returnPhotos()->create([
                'photo_path' => $this->storeDataUrl($payload['return_photo'], 'return', $job->id, 'return'),
                'recorded_by' => $payload['actor_id'] ?? $job->assigned_rider_id,
            ]);
        }
    }

    /**
     * Decode a base64 data URL to a file on the local disk and return its
     * relative path. Non-data-URL strings are stored verbatim as PNG.
     */
    private function storeDataUrl(string $dataUrl, string $dir, int $jobId, string $kind): string
    {
        $extension = 'png';
        $binary = $dataUrl;

        if (preg_match('/^data:(?<mime>[\w\/\-.+]+);base64,(?<data>.+)$/s', $dataUrl, $matches) === 1) {
            $decoded = base64_decode($matches['data'], true);
            if ($decoded !== false) {
                $binary = $decoded;
            }
            $extension = match ($matches['mime']) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/webp' => 'webp',
                default => 'png',
            };
        }

        $path = sprintf('%s/%d/%s_%s.%s', $dir, $jobId, $kind, Str::random(16), $extension);
        Storage::disk('local')->put($path, $binary);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function timelineMeta(JobStatus $to, array $payload): array
    {
        return match ($to) {
            JobStatus::Failed => ['reason' => $this->reasonValue($payload['reason'] ?? null)],
            JobStatus::Delivered => ['photo' => isset($payload['photo']) && $payload['photo'] !== ''],
            default => [],
        };
    }

    private function reasonValue(mixed $reason): ?string
    {
        if ($reason instanceof FailReason) {
            return $reason->value;
        }

        return is_string($reason) ? $reason : null;
    }

    private function describe(JobStatus $to, string $actorRole): string
    {
        return match ($to) {
            JobStatus::Assigned => 'Job assigned to a rider.',
            JobStatus::EnRoutePickup => 'Rider started toward the pickup.',
            JobStatus::AtPickup => 'Rider arrived at the pickup.',
            JobStatus::PickedUp => 'Part collected at the pickup.',
            JobStatus::EnRouteDrop => 'Rider departed for the drop-off.',
            JobStatus::OnSite => 'Rider arrived at the drop-off site.',
            JobStatus::Delivered => 'Delivered with proof of delivery.',
            JobStatus::Failed => 'Delivery attempt failed.',
            JobStatus::Returning => 'Part being returned to the shop.',
            JobStatus::Returned => 'Part returned to the shop.',
            JobStatus::Cancelled => sprintf('Job cancelled by %s.', $actorRole),
            JobStatus::Booked => 'Job re-queued for booking.',
        };
    }

    /**
     * @return array<int, string>
     */
    private function terminalValues(): array
    {
        return array_map(static fn (JobStatus $status): string => $status->value, JobStatus::terminalStatuses());
    }
}

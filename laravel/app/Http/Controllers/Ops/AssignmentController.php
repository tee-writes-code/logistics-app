<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Agents\DispatchAgent;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\AssignRequest;
use App\Http\Requests\Ops\ReorderQueueRequest;
use App\Http\Resources\JobResource;
use App\Models\Job;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Ops manual assignment override and queue reorder. Both delegate to the Dispatch
 * agent, which owns the assignment fields and keeps one active job per rider.
 */
class AssignmentController extends Controller
{
    public function __construct(private readonly DispatchAgent $dispatch) {}

    public function assign(AssignRequest $request, Job $job): JobResource
    {
        // Defense-in-depth: authz never depends solely on the typed request.
        $this->authorize('ops-console');

        // A manual override only makes sense before the run starts; a started or
        // finished job cannot be handed to a different rider.
        if (! in_array($job->status, [JobStatus::Booked, JobStatus::Assigned], true)) {
            abort(Response::HTTP_CONFLICT, 'This job can no longer be reassigned.');
        }

        $rider = User::query()->findOrFail($request->validated('rider_id'));
        $job = $this->dispatch->assign($job, $rider, 'ops');

        return JobResource::make($job->load(['partLine', 'pickupSite', 'assignedRider', 'customer']));
    }

    public function reorderQueue(ReorderQueueRequest $request, User $rider): JsonResponse
    {
        $this->authorize('ops-console');

        if (! $rider->isRider()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            $this->dispatch->reorder($rider, array_map('intval', $request->validated('job_ids')));
        } catch (InvalidArgumentException $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }

        $jobs = Job::query()
            ->where('assigned_rider_id', $rider->id)
            ->whereNotIn('status', array_map(fn (JobStatus $s): string => $s->value, JobStatus::terminalStatuses()))
            ->orderBy('queue_position')
            ->with(['partLine', 'pickupSite'])
            ->get();

        return JobResource::collection($jobs)->response();
    }
}

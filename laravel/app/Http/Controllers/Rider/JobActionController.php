<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rider;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeliverRequest;
use App\Http\Requests\FailRequest;
use App\Http\Requests\ReturnCompleteRequest;
use App\Http\Resources\JobResource;
use App\Models\AgentAsk;
use App\Models\Job;
use App\Services\JobStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Thin rider action endpoints. Each authorizes ownership (403 for another
 * rider's job), guards that the job is the rider's ONE active job (409
 * otherwise), then delegates the actual status write to JobStatusService.
 */
class JobActionController extends Controller
{
    public function __construct(private readonly JobStatusService $status) {}

    public function start(Request $request, Job $job): JobResource
    {
        $this->authorize('execute', $job);

        return $this->drive($request, $job, JobStatus::EnRoutePickup);
    }

    public function arrivePickup(Request $request, Job $job): JobResource
    {
        $this->authorize('execute', $job);

        return $this->drive($request, $job, JobStatus::AtPickup);
    }

    public function collect(Request $request, Job $job): JobResource
    {
        $this->authorize('execute', $job);

        return $this->drive($request, $job, JobStatus::PickedUp);
    }

    public function departDrop(Request $request, Job $job): JobResource
    {
        $this->authorize('execute', $job);

        return $this->drive($request, $job, JobStatus::EnRouteDrop);
    }

    public function arriveSite(Request $request, Job $job): JobResource
    {
        $this->authorize('execute', $job);

        return $this->drive($request, $job, JobStatus::OnSite);
    }

    public function deliver(DeliverRequest $request, Job $job): JobResource
    {
        $this->authorize('execute', $job);

        // Idempotent double-submit: an already-delivered job returns its POD.
        if ($job->status === JobStatus::Delivered) {
            return $this->present($job);
        }

        // A failed/refused job cannot be delivered until an Exception replan
        // (iter-4) re-opens it.
        if ($job->status === JobStatus::Failed) {
            abort(Response::HTTP_CONFLICT, 'This job is awaiting a new plan and cannot be delivered.');
        }

        return $this->drive($request, $job, JobStatus::Delivered, [
            'signature' => $request->validated('signature'),
            'photo' => $request->validated('photo'),
        ]);
    }

    public function fail(FailRequest $request, Job $job): JobResource
    {
        $this->authorize('execute', $job);

        return $this->drive($request, $job, JobStatus::Failed, [
            'reason' => $request->validated('reason'),
            'note' => $request->validated('note'),
        ]);
    }

    public function returnComplete(ReturnCompleteRequest $request, Job $job): JobResource
    {
        $this->authorize('execute', $job);

        return $this->drive($request, $job, JobStatus::Returned, [
            'return_photo' => $request->validated('return_photo'),
        ]);
    }

    /**
     * Guard the active-job rule, run the transition as the rider, and present it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function drive(Request $request, Job $job, JobStatus $to, array $payload = []): JobResource
    {
        if (! $job->isActiveForRider()) {
            abort(Response::HTTP_CONFLICT, 'This job is not your active job.');
        }

        // Defense-in-depth: a job paused by a pending `unsafe` ask can never be
        // driven, even if it were somehow flagged active. The pause is only
        // cleared when the ask is confirmed.
        if (AgentAsk::pendingUnsafeJobIds([$job->id])->isNotEmpty()) {
            abort(Response::HTTP_CONFLICT, 'This job is paused pending a safety review.');
        }

        $updated = $this->status->transition($job, $to, 'rider', $payload + [
            'actor_id' => $request->user()->id,
        ]);

        return $this->present($updated);
    }

    private function present(Job $job): JobResource
    {
        return JobResource::make(
            $job->load(['partLine', 'pickupSite', 'pod', 'failRecords', 'timelineEvents']),
        );
    }
}

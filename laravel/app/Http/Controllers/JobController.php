<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Agents\DispatchAgent;
use App\Enums\JobStatus;
use App\Enums\JobWindow;
use App\Http\Requests\StoreJobRequest;
use App\Http\Requests\UpdateJobRequest;
use App\Http\Resources\JobResource;
use App\Models\Job;
use App\Services\ClockService;
use App\Services\JobStatusService;
use App\Services\JobTimelineService;
use App\Services\SchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class JobController extends Controller
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly JobTimelineService $timeline,
        private readonly ClockService $clock,
        private readonly JobStatusService $status,
        private readonly DispatchAgent $dispatch,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $jobs = Job::visibleTo($request->user())
            ->with(['partLine', 'pickupSite'])
            ->latest()
            ->get();

        return JobResource::collection($jobs);
    }

    public function show(Request $request, Job $job): JobResource
    {
        $this->authorize('view', $job);

        $job->load(['partLine', 'pickupSite', 'timelineEvents']);

        return JobResource::make($job);
    }

    public function store(StoreJobRequest $request): JsonResponse
    {
        $data = $request->validated();
        $window = JobWindow::from($data['window']);

        if ($window === JobWindow::SameDay && ! $this->scheduling->canFinishSameDay($this->draftFor($data))) {
            return response()->json(['offer_next' => true]);
        }

        $job = DB::transaction(function () use ($request, $data, $window): Job {
            $job = $request->user()->jobs()->create([
                'pickup_site_id' => $data['pickup_site_id'],
                'drop_address' => $data['drop_address'],
                'drop_contact_name' => $data['drop_contact_name'],
                'drop_contact_phone' => $data['drop_phone'],
                'notes' => $data['notes'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'window' => $window,
                'status' => JobStatus::Booked,
                'booked_at' => $this->clock->now(),
            ]);

            $job->partLine()->create([
                'name' => $data['part_line']['name'],
                'sku' => $data['part_line']['sku'] ?? null,
                'quantity' => $data['part_line']['qty'],
                'serial' => $data['part_line']['serial'] ?? null,
            ]);

            if ($request->boolean('save_drop')) {
                $request->user()->savedDrops()->updateOrCreate(
                    ['label' => $data['save_drop_label']],
                    [
                        'address' => $data['drop_address'],
                        'contact_name' => $data['drop_contact_name'],
                        'phone' => $data['drop_phone'],
                    ],
                );
            }

            $this->timeline->record($job, 'booked', 'customer', ['window' => $window->value], 'Job booked by customer.');

            return $job;
        });

        // Close the booking -> assign chain: Dispatch assigns synchronously (no
        // queue worker). A fits-same-day or next job becomes `assigned` with a
        // rider, deterministic ETA, and queue position; it stays `booked` only if
        // no rider is assignable.
        $this->dispatch->onBooked($job);

        return JobResource::make($job->refresh()->load(['partLine', 'pickupSite']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateJobRequest $request, Job $job): JobResource
    {
        // Defense-in-depth: authorize here too, so authz never depends solely on
        // the typed request. Owner-only (ops via before) — a rider cannot edit the
        // customer's fields through this route.
        $this->authorize('update', $job);

        $data = $request->validated();

        $dropFields = array_intersect_key($data, array_flip([
            'drop_address', 'drop_contact_name', 'drop_phone', 'instructions',
        ]));

        if ($dropFields !== [] && ! $job->isEditableDropStage()) {
            abort(Response::HTTP_CONFLICT, 'Drop and contact details can no longer be edited for this job.');
        }

        if (array_key_exists('notes', $data) && ! $job->isNotesEditable()) {
            abort(Response::HTTP_CONFLICT, 'Notes can no longer be edited for this job.');
        }

        $changes = [];

        foreach (['drop_address', 'drop_contact_name', 'instructions', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $job->{$field} = $data[$field];
                $changes[] = $field;
            }
        }

        if (array_key_exists('drop_phone', $data)) {
            $job->drop_contact_phone = $data['drop_phone'];
            $changes[] = 'drop_contact_phone';
        }

        $job->save();

        $this->timeline->record($job, 'edited', 'customer', ['fields' => $changes], 'Job details edited by customer.');

        return JobResource::make($job->load(['partLine', 'pickupSite']));
    }

    public function cancel(Request $request, Job $job): JobResource
    {
        $this->authorize('cancel', $job);

        // Route the status write through the single owner (JobStatusService).
        // An out-of-window cancel (past pickup) is rejected there as a 409, since
        // the cancel edges (booked/assigned -> cancelled) are not legal from a
        // later status.
        $job = $this->status->transition($job, JobStatus::Cancelled, 'customer');

        return JobResource::make($job->load(['partLine', 'pickupSite']));
    }

    /**
     * Build an unsaved job used only to evaluate same-day feasibility.
     *
     * @param  array<string, mixed>  $data
     */
    private function draftFor(array $data): Job
    {
        return new Job([
            'pickup_site_id' => $data['pickup_site_id'],
            'window' => JobWindow::SameDay,
        ]);
    }
}

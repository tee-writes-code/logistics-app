<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Agents\DispatchAgent;
use App\Enums\JobStatus;
use App\Enums\JobWindow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\StoreOpsJobRequest;
use App\Http\Requests\Ops\UpdateOpsJobRequest;
use App\Http\Resources\JobResource;
use App\Models\Job;
use App\Models\User;
use App\Services\ClockService;
use App\Services\JobStatusService;
use App\Services\JobTimelineService;
use App\Services\SchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Ops create / edit / cancel of jobs on behalf of a customer. Booking closes the
 * same booking->assign chain as the customer path (Dispatch runs synchronously).
 */
class OpsJobController extends Controller
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly JobTimelineService $timeline,
        private readonly ClockService $clock,
        private readonly JobStatusService $status,
        private readonly DispatchAgent $dispatch,
    ) {}

    public function store(StoreOpsJobRequest $request): JsonResponse
    {
        // Defense-in-depth: authz never depends solely on the typed request.
        $this->authorize('ops-console');

        $data = $request->validated();
        $window = JobWindow::from($data['window']);
        $customer = User::query()->findOrFail($data['customer_id']);

        if ($window === JobWindow::SameDay && ! $this->scheduling->canFinishSameDay($this->draftFor($data))) {
            return response()->json(['offer_next' => true]);
        }

        $job = DB::transaction(function () use ($customer, $data, $window): Job {
            $job = $customer->jobs()->create([
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

            $this->timeline->record($job, 'booked', 'ops', ['window' => $window->value], 'Job booked by ops on behalf of the customer.');

            return $job;
        });

        $this->dispatch->onBooked($job);

        return JobResource::make($job->refresh()->load(['partLine', 'pickupSite', 'assignedRider', 'customer']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateOpsJobRequest $request, Job $job): JobResource
    {
        // Defense-in-depth: authz never depends solely on the typed request.
        $this->authorize('ops-console');

        $data = $request->validated();
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

        $this->timeline->record($job, 'edited', 'ops', ['fields' => $changes], 'Job details edited by ops.');

        return JobResource::make($job->load(['partLine', 'pickupSite', 'assignedRider', 'customer']));
    }

    public function cancel(Job $job): JobResource
    {
        $this->authorize('ops-console');

        // Cancel-until-pickup is enforced by the transition map: booked/assigned ->
        // cancelled is legal, later statuses are not (rejected there as a 409).
        $job = $this->status->transition($job, JobStatus::Cancelled, 'ops');

        return JobResource::make($job->load(['partLine', 'pickupSite', 'assignedRider', 'customer']));
    }

    /**
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Enums\JobStatus;
use App\Enums\JobWindow;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Ops jobs board: every job across all customers and riders, with filters by
 * status, rider, and window. Ops-only.
 */
class JobBoardController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('ops-console');

        $jobs = Job::query()
            ->with(['partLine', 'pickupSite', 'assignedRider', 'customer', 'agentAsks'])
            ->when(
                $request->filled('status') && JobStatus::tryFrom((string) $request->query('status')) !== null,
                fn ($query) => $query->where('status', $request->query('status')),
            )
            ->when(
                $request->filled('rider_id'),
                fn ($query) => $query->where('assigned_rider_id', (int) $request->query('rider_id')),
            )
            ->when(
                $request->filled('window') && JobWindow::tryFrom((string) $request->query('window')) !== null,
                fn ($query) => $query->where('window', $request->query('window')),
            )
            ->latest()
            ->get();

        return JobResource::collection($jobs);
    }

    public function show(Request $request, Job $job): JobResource
    {
        $this->authorize('ops-console');

        $job->load([
            'partLine', 'pickupSite', 'assignedRider', 'customer',
            'timelineEvents', 'failRecords', 'pod', 'agentAsks', 'agentActionLogs',
        ]);

        return JobResource::make($job);
    }
}

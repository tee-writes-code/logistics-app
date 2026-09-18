<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rider;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class QueueController extends Controller
{
    /**
     * The signed-in rider's own live queue, ordered by position, with the single
     * active job flagged (via is_active_for_rider on each row). Terminal jobs are
     * excluded; an empty queue returns [].
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $rider = $request->user();

        $jobs = Job::visibleTo($rider)
            ->whereNotIn('status', array_map(
                static fn (JobStatus $status): string => $status->value,
                JobStatus::terminalStatuses(),
            ))
            ->with(['partLine', 'pickupSite', 'pod', 'failRecords'])
            ->orderByRaw('queue_position is null, queue_position asc')
            ->orderBy('id')
            ->get();

        return JobResource::collection($jobs);
    }
}

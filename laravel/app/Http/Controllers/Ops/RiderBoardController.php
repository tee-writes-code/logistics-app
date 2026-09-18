<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Ops rider board: every rider with their live queue (ordered) and load.
 */
class RiderBoardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('ops-console');

        $terminal = array_map(fn (JobStatus $s): string => $s->value, JobStatus::terminalStatuses());

        $riders = User::riders()
            ->with(['assignedJobs' => function ($query) use ($terminal): void {
                $query->whereNotIn('status', $terminal)
                    ->with(['partLine', 'pickupSite'])
                    ->orderBy('queue_position');
            }])
            ->orderBy('id')
            ->get();

        $data = $riders->map(fn (User $rider): array => [
            'id' => $rider->id,
            'name' => $rider->name,
            'email' => $rider->email,
            'load' => $rider->assignedJobs->count(),
            'queue' => JobResource::collection($rider->assignedJobs)->resolve($request),
        ])->all();

        return response()->json(['data' => $data]);
    }
}

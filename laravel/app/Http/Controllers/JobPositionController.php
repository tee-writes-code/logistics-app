<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\User;
use App\Services\MagicLinkService;
use App\Services\MockMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Mock live-map position for a job. Visible only to the four allowed audiences —
 * the owning customer, the assigned rider, ops, and the recipient (via token) —
 * and only within the live-map window (from picked_up until terminal). Outside
 * the window the position is null; the frontend then shows status + ETA only.
 *
 * This route is intentionally not behind auth:sanctum so the unauthenticated
 * recipient can poll it with their token; logged-in audiences are still resolved
 * through the sanctum guard from the SPA session.
 */
class JobPositionController extends Controller
{
    public function show(Request $request, Job $job, MockMapService $map, MagicLinkService $magicLinks): JsonResponse
    {
        if (! $this->canView($request, $job, $magicLinks)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return response()->json(['data' => $map->positionFor($job)]);
    }

    private function canView(Request $request, Job $job, MagicLinkService $magicLinks): bool
    {
        $token = $request->query('token');

        if (is_string($token) && $token !== '') {
            $resolved = $magicLinks->resolveByToken($token);

            return $resolved !== null && $resolved->getKey() === $job->getKey();
        }

        $user = $request->user() ?? auth('sanctum')->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isOps()) {
            return true;
        }

        if ($user->isCustomer()) {
            return $job->customer_id === $user->id;
        }

        if ($user->isRider()) {
            return $job->assigned_rider_id === $user->id;
        }

        return false;
    }
}

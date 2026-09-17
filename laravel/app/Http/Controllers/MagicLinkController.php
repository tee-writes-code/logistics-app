<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ShowMagicLinkRequest;
use App\Models\Job;
use App\Services\MagicLinkService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MagicLinkController extends Controller
{
    /**
     * Resolve a signed recipient magic link and return the scoped job.
     *
     * The `signed` middleware validates the URL signature before this runs;
     * here we additionally validate the token, session state, and job scope.
     */
    public function show(ShowMagicLinkRequest $request, Job $job, MagicLinkService $magicLinks): JsonResponse
    {
        $session = $magicLinks->resolve($job, (string) $request->validated('token'));

        if ($session === null) {
            throw new HttpException(403, 'This magic link is no longer valid.');
        }

        $job->load(['partLine', 'pickupSite', 'timelineEvents', 'recipientActions']);

        return response()->json([
            'job' => $job,
        ]);
    }
}

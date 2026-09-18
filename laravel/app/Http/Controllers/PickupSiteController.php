<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\JobStatus;
use App\Http\Requests\StorePickupSiteRequest;
use App\Http\Requests\UpdatePickupSiteRequest;
use App\Http\Resources\PickupSiteResource;
use App\Models\PickupSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PickupSiteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PickupSiteResource::collection(
            $request->user()->pickupSites()->latest()->get()
        );
    }

    public function store(StorePickupSiteRequest $request): JsonResponse
    {
        $site = $request->user()->pickupSites()->create($request->validated());

        return PickupSiteResource::make($site)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePickupSiteRequest $request, PickupSite $pickupSite): PickupSiteResource
    {
        $pickupSite->update($request->validated());

        return PickupSiteResource::make($pickupSite);
    }

    public function destroy(Request $request, PickupSite $pickupSite): Response|JsonResponse
    {
        abort_unless($pickupSite->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);

        $terminal = array_map(fn (JobStatus $status): string => $status->value, JobStatus::terminalStatuses());

        if ($pickupSite->jobs()->whereNotIn('status', $terminal)->exists()) {
            return response()->json([
                'message' => 'This pickup site is used by an open job and cannot be deleted.',
            ], Response::HTTP_CONFLICT);
        }

        $pickupSite->delete();

        return response()->noContent();
    }
}

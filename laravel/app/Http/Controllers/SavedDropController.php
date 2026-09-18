<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavedDropRequest;
use App\Http\Requests\UpdateSavedDropRequest;
use App\Http\Resources\SavedDropResource;
use App\Models\SavedDrop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SavedDropController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SavedDropResource::collection(
            $request->user()->savedDrops()->latest()->get()
        );
    }

    public function store(StoreSavedDropRequest $request): JsonResponse
    {
        $drop = $request->user()->savedDrops()->create($request->validated());

        return SavedDropResource::make($drop)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateSavedDropRequest $request, SavedDrop $savedDrop): SavedDropResource
    {
        $savedDrop->update($request->validated());

        return SavedDropResource::make($savedDrop);
    }

    public function destroy(Request $request, SavedDrop $savedDrop): Response
    {
        abort_unless($savedDrop->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);

        $savedDrop->delete();

        return response()->noContent();
    }
}

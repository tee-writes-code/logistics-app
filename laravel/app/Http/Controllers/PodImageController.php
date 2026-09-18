<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves POD and return images off the local sibling storage disk. Guarded by
 * the job `view` policy so only the owning customer, the assigned rider, or ops
 * can fetch an image. `?type=` selects which artifact (signature default).
 */
class PodImageController extends Controller
{
    public function show(Request $request, Job $job): StreamedResponse
    {
        $this->authorize('view', $job);

        $type = $request->query('type', 'signature');

        $path = match ($type) {
            'signature' => $job->pod?->signature_path,
            'photo' => $job->pod?->photo_path,
            'return' => $job->returnPhotos()->latest('id')->first()?->photo_path,
            default => null,
        };

        abort_if($path === null, Response::HTTP_NOT_FOUND, 'No image available for this job.');

        $disk = Storage::disk('local');

        abort_unless($disk->exists($path), Response::HTTP_NOT_FOUND, 'Image file is missing.');

        return $disk->response($path);
    }
}

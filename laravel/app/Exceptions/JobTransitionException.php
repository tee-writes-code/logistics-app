<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\JobStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Thrown when a caller asks JobStatusService to move a job across an edge that
 * is not in the allowed-transition map. Renders as a 409 that lists the legal
 * next states from the job's current status.
 */
class JobTransitionException extends RuntimeException
{
    /**
     * @param  array<int, JobStatus>  $allowed
     */
    public function __construct(
        public readonly JobStatus $from,
        public readonly JobStatus $to,
        public readonly array $allowed,
    ) {
        parent::__construct(sprintf(
            'Cannot move job from "%s" to "%s". Allowed next states: %s.',
            $from->value,
            $to->value,
            $this->allowed === [] ? 'none (terminal)' : implode(', ', $this->allowedValues()),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function allowedValues(): array
    {
        return array_map(static fn (JobStatus $status): string => $status->value, $this->allowed);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'allowed' => $this->allowedValues(),
        ], Response::HTTP_CONFLICT);
    }
}

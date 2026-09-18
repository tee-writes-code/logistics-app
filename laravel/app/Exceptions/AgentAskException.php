<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Thrown while resolving an AgentAsk when the resolution cannot proceed: the ask
 * was already resolved (a confirm race) or the chosen plan no longer fits. Self
 * renders as a 409 so the SPA can surface the reason.
 */
class AgentAskException extends RuntimeException
{
    private function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function alreadyResolved(): self
    {
        return new self('This request has already been resolved.', Response::HTTP_CONFLICT);
    }

    public static function reattemptNoLongerFits(): self
    {
        return new self(
            'A same-day reattempt no longer fits today\'s hours; choose next window or return.',
            Response::HTTP_CONFLICT,
        );
    }

    public static function invalidDecision(): self
    {
        return new self('That decision is not valid for this request.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}

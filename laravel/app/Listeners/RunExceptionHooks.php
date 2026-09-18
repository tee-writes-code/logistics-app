<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Agents\ExceptionAgent;
use App\Enums\FailReason;
use App\Enums\JobStatus;
use App\Events\JobTransitioned;

/**
 * Fires the Exception agent when a job transitions to `failed`. A recipient
 * refusal routes to onRefuse; a rider-reported failure routes to onFail. Both
 * run synchronously, after the notification listener, on the same seam.
 *
 * The agent's own replan transitions (en_route_drop, booked, returning) never
 * land on `failed`, so this listener does not re-enter for them.
 */
class RunExceptionHooks
{
    public function __construct(private readonly ExceptionAgent $exception) {}

    public function handle(JobTransitioned $event): void
    {
        if ($event->to !== JobStatus::Failed) {
            return;
        }

        $job = $event->job;

        if ($this->isRefusal($event)) {
            $this->exception->onRefuse($job);

            return;
        }

        $this->exception->onFail($job);
    }

    private function isRefusal(JobTransitioned $event): bool
    {
        if ($event->actorRole === 'recipient') {
            return true;
        }

        $reason = $event->payload['reason'] ?? null;

        return $reason === FailReason::Refused || $reason === FailReason::Refused->value;
    }
}

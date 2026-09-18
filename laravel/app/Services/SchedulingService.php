<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Job;

/**
 * Deterministic same-day feasibility. A job can finish same-day only when it is
 * still before the cutoff AND enough business time remains today to cover every
 * leg of the run. Keys off ClockService so the demo clock (iter-5) flows through
 * automatically; the Dispatch agent (iter-4) reuses this exact check.
 */
class SchedulingService
{
    public function __construct(private readonly ClockService $clock) {}

    /**
     * Whether the given (possibly unsaved) draft job can be completed same-day.
     */
    public function canFinishSameDay(Job $draft): bool
    {
        if ($this->clock->businessHoursToday() === null) {
            return false;
        }

        if (! $this->clock->isBeforeCutoff()) {
            return false;
        }

        return $this->businessMinutesRemaining() >= $this->totalLegMinutes();
    }

    /**
     * Minutes of business time left between now and today's close (0 if closed
     * or already past close). Time before opening counts from open.
     */
    private function businessMinutesRemaining(): int
    {
        $hours = $this->clock->businessHoursToday();

        if ($hours === null) {
            return 0;
        }

        $now = $this->clock->now();

        if ($now->greaterThanOrEqualTo($hours['close'])) {
            return 0;
        }

        $start = $now->lessThan($hours['open']) ? $hours['open'] : $now;

        return (int) $start->diffInMinutes($hours['close']);
    }

    /**
     * Total minute estimate for one job across all legs.
     */
    private function totalLegMinutes(): int
    {
        $legs = config('logistics.leg_minutes', []);

        return (int) array_sum(is_array($legs) ? $legs : []);
    }
}

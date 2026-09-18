<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\Job;

/**
 * Mock live-map positioning. There is no map/GPS: a rider position is computed
 * deterministically by interpolating a straight seeded route from the pickup to
 * the drop, advanced by how long the job has been in flight.
 *
 * Coordinates are unit-square points (x/y in [0,1]); the frontend LiveMap draws
 * them on an SVG. Time comes from ClockService so iter-5's advancing demo clock
 * animates the point without any change here.
 */
class MockMapService
{
    public function __construct(private readonly ClockService $clock) {}

    /**
     * A deterministic position for a job, or null when the job is outside the
     * live-map window (before pickup or once terminal).
     *
     * @return array<string, mixed>|null
     */
    public function positionFor(Job $job): ?array
    {
        if ($job->isTerminal() || ! in_array($job->status, JobStatus::liveMapStatuses(), true)) {
            return null;
        }

        $pickup = $this->endpoint('pickup', $job);
        $drop = $this->endpoint('drop', $job);
        $progress = $this->progress($job);

        return [
            'pickup' => $pickup,
            'drop' => $drop,
            'current' => [
                'x' => $this->lerp($pickup['x'], $drop['x'], $progress),
                'y' => $this->lerp($pickup['y'], $drop['y'], $progress),
            ],
            'progress' => round($progress, 4),
            'status' => $job->status->value,
        ];
    }

    /**
     * Fraction of the drop leg elapsed since pickup, clamped to [0, 1].
     */
    private function progress(Job $job): float
    {
        if ($job->picked_up_at === null) {
            return 0.0;
        }

        $legMinutes = (int) (config('logistics.leg_minutes.to_drop') ?? 45);
        if ($legMinutes <= 0) {
            $legMinutes = 45;
        }

        $elapsed = $job->picked_up_at->diffInMinutes($this->clock->now(), false);

        return max(0.0, min(1.0, $elapsed / $legMinutes));
    }

    /**
     * A stable unit-square endpoint for the pickup or drop of a job.
     *
     * @return array{x: float, y: float}
     */
    private function endpoint(string $role, Job $job): array
    {
        $seed = $role === 'pickup'
            ? 'pickup:'.($job->pickup_site_id ?? 'none')
            : 'drop:'.$job->id.':'.$job->drop_address;

        return [
            'x' => $this->unit($seed.':x'),
            'y' => $this->unit($seed.':y'),
        ];
    }

    private function unit(string $seed): float
    {
        return round(hexdec(substr(md5($seed), 0, 6)) / 0xFFFFFF, 4);
    }

    private function lerp(float $a, float $b, float $t): float
    {
        return round($a + ($b - $a) * $t, 4);
    }
}

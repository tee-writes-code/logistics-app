<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Agents\ExceptionAgent;
use App\Enums\AgentAskStatus;
use App\Enums\FailReason;
use App\Enums\JobStatus;
use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Demo\SetClockRequest;
use App\Http\Resources\JobResource;
use App\Http\Resources\NotificationResource;
use App\Models\Job;
use App\Models\Notification;
use App\Services\ClockService;
use App\Services\FixtureResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Demo-only operator controls (iter-5). Every action is thin: it sets the clock
 * override or calls an existing iter-4 agent hook. No new product or domain logic
 * lives here — the four scripted paths exercise iters 1..4. The whole controller
 * is guarded by the `demo` middleware (Ops role + non-production).
 */
class DemoController extends Controller
{
    public function __construct(
        private readonly ClockService $clock,
        private readonly ExceptionAgent $exception,
        private readonly FixtureResetService $fixtures,
    ) {}

    /**
     * Current clock override state (for the panel banner / initial load).
     */
    public function clock(): JsonResponse
    {
        return response()->json(['data' => $this->clockState()]);
    }

    /**
     * Set the demo clock to a preset (before/after cutoff). Stored as an advancing
     * offset so the clock keeps ticking during the demo.
     */
    public function setClock(SetClockRequest $request): JsonResponse
    {
        $preset = (string) $request->validated('preset');
        $this->clock->setOverride($this->clock->presetInstant($preset), $preset);

        return response()->json(['data' => $this->clockState()]);
    }

    /**
     * Clear the clock override; the clock returns to real time.
     */
    public function clearClock(): JsonResponse
    {
        $this->clock->clearOverride();

        return response()->json(['data' => $this->clockState()]);
    }

    /**
     * Trigger a delay on a rider's job: the Exception agent reassigns it to another
     * rider and rebuilds both queues, with no confirmation.
     */
    public function triggerDelay(Job $job): JsonResponse
    {
        if ($job->assigned_rider_id === null || $job->status->isTerminal() || $job->status === JobStatus::Booked) {
            abort(Response::HTTP_CONFLICT, 'This job is not in a state that can be delayed.');
        }

        $this->exception->onDelay($job);

        return response()->json(['data' => $this->presentJob($job->refresh())]);
    }

    /**
     * Trigger an unsafe situation on a rider's in-flight job: the Exception agent
     * pauses it (rider stops, the job leaves the active slot and stays out of it
     * through any recompute) and raises an Ops-confirm ask. Resuming happens on
     * confirm. Mirrors the delay trigger's eligibility guard.
     */
    public function triggerUnsafe(Job $job): JsonResponse
    {
        if ($job->assigned_rider_id === null || $job->status->isTerminal() || $job->status === JobStatus::Booked) {
            abort(Response::HTTP_CONFLICT, 'This job is not in a state that can be paused.');
        }

        $hasPendingAsk = $job->agentAsks()
            ->where('status', AgentAskStatus::Pending)
            ->exists();

        if ($hasPendingAsk) {
            abort(Response::HTTP_CONFLICT, 'This job already has a pending agent request.');
        }

        $this->exception->onUnsafe($job);

        return response()->json(['data' => $this->presentJob($job->refresh())]);
    }

    /**
     * Force "hours no longer fit" after a failed attempt: move the clock past the
     * cutoff and (re)invoke the Exception decision so it takes the ask branch
     * (next for a rider fail, return for a recipient refusal). Idempotent — an
     * existing pending ask is returned as-is.
     */
    public function forceMissHours(Job $job): JsonResponse
    {
        if ($job->status !== JobStatus::Failed) {
            abort(Response::HTTP_CONFLICT, 'Force-miss-hours applies only to a failed job.');
        }

        $this->clock->setOverride(
            $this->clock->presetInstant(ClockService::PRESET_AFTER_CUTOFF),
            ClockService::PRESET_AFTER_CUTOFF,
        );

        $hasPendingAsk = $job->agentAsks()
            ->where('status', AgentAskStatus::Pending)
            ->exists();

        if (! $hasPendingAsk) {
            // The job is `failed` but carries no ask (the failure was recorded
            // without the exception hook running, e.g. a seeded/imported failed
            // job): run the exception decision now that the clock is past cutoff so
            // it takes the ask branch (next for a fail, return for a refusal).
            $this->wasRefused($job)
                ? $this->exception->onRefuse($job)
                : $this->exception->onFail($job);
        }

        return response()->json(['data' => $this->presentJob($job->refresh())]);
    }

    /**
     * The mock SMS outbox (reuses the iter-3 recipient-link messages).
     */
    public function smsOutbox(): AnonymousResourceCollection
    {
        $messages = Notification::query()
            ->where('channel', NotificationChannel::Sms)
            ->with('job:id,status')
            ->latest()
            ->limit(100)
            ->get();

        return NotificationResource::collection($messages);
    }

    /**
     * Reset the demo fixtures to the seeded state and clear the clock override.
     */
    public function reset(): JsonResponse
    {
        $this->fixtures->reset();

        return response()->json(['data' => $this->clockState()]);
    }

    private function wasRefused(Job $job): bool
    {
        $latest = $job->failRecords()->latest('id')->first();

        return $latest?->reason === FailReason::Refused;
    }

    private function presentJob(Job $job): JobResource
    {
        return JobResource::make(
            $job->load(['partLine', 'pickupSite', 'assignedRider', 'agentAsks', 'timelineEvents']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function clockState(): array
    {
        return [
            'now' => $this->clock->now()->toIso8601String(),
            'override' => $this->clock->override(),
        ];
    }
}

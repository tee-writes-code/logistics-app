<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipient;

use App\Agents\ExceptionAgent;
use App\Enums\FailReason;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecipientHoldRequest;
use App\Http\Requests\RecipientInstructionsRequest;
use App\Http\Requests\RecipientRefuseRequest;
use App\Models\Job;
use App\Models\RecipientAction;
use App\Services\ClockService;
use App\Services\JobStatusService;
use App\Services\MagicLinkService;
use App\Services\RecipientViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Recipient actions taken through the magic link, all unauthenticated and scoped
 * by the URL token. Every action re-validates the token AND the job's current
 * status window before it writes:
 *
 *  - instructions / hold: allowed until the rider is on site (rejected at/after).
 *  - receive-confirm / refuse: allowed only while the rider is on site.
 *
 * All recipient actions persist to the single `recipient_actions` table, keyed by
 * a `type` discriminator; hold release and refuse reasons live in `meta`.
 */
class RecipientActionController extends Controller
{
    public function __construct(
        private readonly MagicLinkService $magicLinks,
        private readonly RecipientViewService $view,
        private readonly JobStatusService $status,
        private readonly ClockService $clock,
        private readonly ExceptionAgent $exception,
    ) {}

    public function instructions(RecipientInstructionsRequest $request, string $token): JsonResponse
    {
        $job = $this->resolveOrAbort($token);
        $this->assertBeforeOnSite($job, 'Delivery instructions can no longer be added; the rider is on site.');

        $job->recipientActions()->create([
            'type' => 'delivery_instruction',
            'note' => $request->validated('body'),
        ]);

        return $this->present($job, $token);
    }

    public function hold(RecipientHoldRequest $request, string $token): JsonResponse
    {
        $job = $this->resolveOrAbort($token);
        $this->assertBeforeOnSite($job, 'A hold can no longer be placed; the rider is on site.');

        $missesHours = ! $this->clock->isBeforeCutoff();

        $job->recipientActions()->create([
            'type' => 'hold',
            'note' => $request->validated('note'),
            'meta' => [
                'released_at' => null,
                // Flag holds that would push the job past today's hours/cutoff.
                'flag_miss_hours' => $missesHours,
            ],
        ]);

        // iter-4: a hold that misses today's hours hands off to the Exception
        // agent, which raises a confirm ask to reschedule to the next window.
        if ($missesHours) {
            $this->exception->onHoldMissesHours($job);
        }

        return $this->present($job, $token);
    }

    public function releaseHold(string $token): JsonResponse
    {
        $job = $this->resolveOrAbort($token);
        $this->assertBeforeOnSite($job, 'A hold can no longer be changed; the rider is on site.');

        $active = $job->recipientActions()
            ->where('type', 'hold')
            ->get()
            ->first(fn (RecipientAction $action): bool => ($action->meta['released_at'] ?? null) === null);

        if ($active === null) {
            abort(Response::HTTP_CONFLICT, 'There is no active hold to release.');
        }

        $active->update([
            'meta' => array_merge($active->meta ?? [], [
                'released_at' => $this->clock->now()->toIso8601String(),
            ]),
        ]);

        return $this->present($job, $token);
    }

    public function receiveConfirm(string $token): JsonResponse
    {
        $job = $this->resolveOrAbort($token);
        $this->assertOnSite($job, 'Receiving can only be confirmed while the rider is on site.');

        // Records the recipient's intent; the rider's POD still closes the job.
        $job->recipientActions()->create(['type' => 'receive_confirm']);

        return $this->present($job, $token);
    }

    public function refuse(RecipientRefuseRequest $request, string $token): JsonResponse
    {
        $job = $this->resolveOrAbort($token);
        $this->assertOnSite($job, 'A delivery can only be refused while the rider is on site.');

        $reason = (string) $request->validated('reason');

        // Route the failure through the single status writer. This sets `failed`,
        // which blocks a later rider deliver (the iter-2 rule) until an Exception
        // replan re-opens the job.
        $this->status->transition($job, JobStatus::Failed, 'recipient', [
            'reason' => FailReason::Refused,
            'note' => $reason,
            'actor_id' => null,
        ]);

        $job->recipientActions()->create([
            'type' => 'refuse',
            'note' => $reason,
            'meta' => ['reason' => $reason],
        ]);

        return $this->present($job->refresh(), $token);
    }

    private function resolveOrAbort(string $token): Job
    {
        $job = $this->magicLinks->resolveByToken($token);

        if ($job === null) {
            abort(Response::HTTP_FORBIDDEN, 'This link is no longer valid.');
        }

        return $job;
    }

    private function assertBeforeOnSite(Job $job, string $message): void
    {
        if ($job->status === JobStatus::OnSite) {
            abort(Response::HTTP_CONFLICT, $message);
        }
    }

    private function assertOnSite(Job $job, string $message): void
    {
        if ($job->status !== JobStatus::OnSite) {
            abort(Response::HTTP_CONFLICT, $message);
        }
    }

    private function present(Job $job, string $token): JsonResponse
    {
        return response()->json(['data' => $this->view->present($job, $token)]);
    }
}

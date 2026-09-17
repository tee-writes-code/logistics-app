<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Job;
use App\Models\MagicLinkSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class MagicLinkService
{
    /**
     * Issue a fresh recipient magic-link session for a job, revoking any
     * currently-active session first (reissue invalidates the old link).
     *
     * Revoke + insert run in a single transaction with a row lock on the job's
     * existing sessions, so two concurrent reissues cannot both leave a valid
     * link behind — at most one active session per job survives.
     */
    public function issueFor(Job $job, ?Carbon $expiresAt = null): MagicLinkSession
    {
        return DB::transaction(function () use ($job, $expiresAt): MagicLinkSession {
            $job->magicLinkSession()
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->update(['revoked_at' => now()]);

            return $job->magicLinkSession()->create([
                'token' => Str::random(48),
                'expires_at' => $expiresAt ?? now()->addDay(),
            ]);
        });
    }

    /**
     * Build the signed URL a recipient uses to open their job.
     */
    public function urlFor(MagicLinkSession $session): string
    {
        return URL::temporarySignedRoute(
            'recipient.jobs.show',
            $session->expires_at,
            ['job' => $session->job_id, 'token' => $session->token],
        );
    }

    /**
     * Resolve the valid session for a job + token, or null when the token does
     * not match, is revoked, is expired, or the job has reached a terminal state.
     */
    public function resolve(Job $job, string $token): ?MagicLinkSession
    {
        if ($job->status->isTerminal()) {
            return null;
        }

        $session = $job->magicLinkSession()
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->first();

        if ($session === null || ! $session->isValid()) {
            return null;
        }

        return $session;
    }
}

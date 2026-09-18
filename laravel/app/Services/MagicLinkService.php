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
     * Build the signed API URL a recipient uses to open their job. Retained for
     * the signed-route access path and its security tests; the SPA recipient view
     * is reached through trackUrlFor().
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
     * Build the client SPA URL a recipient opens: an unauthenticated hash route
     * scoped by the session token. This is the link carried in the mock SMS row.
     */
    public function trackUrlFor(MagicLinkSession $session): string
    {
        return rtrim((string) config('app.url'), '/').'/#/track/'.$session->token;
    }

    /**
     * Resolve the single live job for a bare token (token-first, no job id in the
     * URL), or null when the token does not match a session, the session is
     * revoked or expired, or the job has reached a terminal state.
     *
     * The token is a 48-char unguessable secret on a unique index, so it alone
     * scopes access to exactly one job — a second job is never reachable.
     */
    public function resolveByToken(string $token): ?Job
    {
        $session = MagicLinkSession::query()
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->first();

        if ($session === null || ! $session->isValid()) {
            return null;
        }

        $job = $session->job;

        if ($job === null || $job->status->isTerminal()) {
            return null;
        }

        return $job;
    }

    /**
     * Revoke any active magic-link session for a job. Called when a job reaches a
     * terminal state (delivered/returned/cancelled) so the link becomes read-only.
     */
    public function expire(Job $job): void
    {
        $job->magicLinkSession()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
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

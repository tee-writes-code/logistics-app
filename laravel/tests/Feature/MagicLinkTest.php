<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\MagicLinkSession;
use App\Services\MagicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_signed_link_returns_the_scoped_job(): void
    {
        $job = Job::factory()->status(JobStatus::EnRouteDrop)->create();
        $service = app(MagicLinkService::class);
        $session = $service->issueFor($job);

        $this->getJson($service->urlFor($session))
            ->assertOk()
            ->assertJsonPath('job.id', $job->id);
    }

    public function test_reissuing_a_link_revokes_the_previous_session(): void
    {
        $job = Job::factory()->status(JobStatus::EnRouteDrop)->create();
        $service = app(MagicLinkService::class);

        $first = $service->issueFor($job);
        $second = $service->issueFor($job);

        $this->assertNotNull($first->fresh()->revoked_at, 'The prior session should be revoked.');
        $this->assertNull($second->fresh()->revoked_at, 'The new session should be active.');
        $this->assertSame(1, $job->magicLinkSession()->whereNull('revoked_at')->count());

        // The old link no longer resolves; the new one does.
        $this->assertNull($service->resolve($job, $first->token));
        $this->assertNotNull($service->resolve($job, $second->token));
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $job = Job::factory()->status(JobStatus::EnRouteDrop)->create();
        $session = MagicLinkSession::factory()->for($job)->create();

        $url = "/api/recipient/jobs/{$job->id}?token={$session->token}&signature=deadbeef";

        $this->getJson($url)->assertForbidden();
    }

    public function test_expired_session_is_rejected_even_with_a_valid_signature(): void
    {
        $job = Job::factory()->status(JobStatus::EnRouteDrop)->create();
        $session = MagicLinkSession::factory()->for($job)->expired()->create();

        // A structurally valid (non-expiring) signature, but the session is expired.
        $url = URL::signedRoute('recipient.jobs.show', [
            'job' => $job->id,
            'token' => $session->token,
        ]);

        $this->getJson($url)->assertForbidden();
    }

    public function test_link_is_rejected_once_the_job_is_terminal(): void
    {
        $job = Job::factory()->status(JobStatus::Delivered)->create();
        $session = MagicLinkSession::factory()->for($job)->create();

        $url = URL::signedRoute('recipient.jobs.show', [
            'job' => $job->id,
            'token' => $session->token,
        ]);

        $this->getJson($url)->assertForbidden();
    }
}

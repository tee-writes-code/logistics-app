<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\MagicLinkSession;
use App\Models\User;
use App\Services\MagicLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecipientTrackTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function magicLinks(): MagicLinkService
    {
        return app(MagicLinkService::class);
    }

    private function jobWithSession(JobStatus $status, ?User $rider = null): array
    {
        $job = Job::factory()
            ->status($status)
            ->create([
                'assigned_rider_id' => $rider?->id,
                'is_active_for_rider' => $rider !== null && ! $status->isTerminal(),
                'queue_position' => $rider !== null ? 1 : null,
                'picked_up_at' => in_array($status, JobStatus::liveMapStatuses(), true) ? now()->subMinutes(10) : null,
            ]);

        $session = $this->magicLinks()->issueFor($job);

        return [$job, $session->token];
    }

    public function test_token_resolves_exactly_one_job_and_never_a_second(): void
    {
        [$jobA, $tokenA] = $this->jobWithSession(JobStatus::EnRouteDrop);
        [$jobB, $tokenB] = $this->jobWithSession(JobStatus::EnRouteDrop);

        $this->getJson("/api/track/{$tokenA}")
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.job.id', $jobA->id);

        $this->getJson("/api/track/{$tokenB}")
            ->assertOk()
            ->assertJsonPath('data.job.id', $jobB->id);

        $this->assertNotSame($jobA->id, $jobB->id);
    }

    public function test_track_payload_includes_status_and_is_terminal(): void
    {
        // The SPA's live map stops polling on job.is_terminal, so the payload
        // must carry both status and is_terminal for a live job.
        [$job, $token] = $this->jobWithSession(JobStatus::EnRouteDrop);

        $this->getJson("/api/track/{$token}")
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.job.status', JobStatus::EnRouteDrop->value)
            ->assertJsonPath('data.job.is_terminal', false);

        $this->assertFalse($job->fresh()->isTerminal());
    }

    public function test_expired_token_renders_read_only(): void
    {
        $job = Job::factory()->status(JobStatus::EnRouteDrop)->create();
        $session = MagicLinkSession::factory()->for($job)->expired()->create();

        $this->getJson("/api/track/{$session->token}")
            ->assertOk()
            ->assertJsonPath('data.valid', false);
    }

    public function test_revoked_token_renders_read_only(): void
    {
        $job = Job::factory()->status(JobStatus::EnRouteDrop)->create();
        $session = MagicLinkSession::factory()->for($job)->revoked()->create();

        $this->getJson("/api/track/{$session->token}")
            ->assertOk()
            ->assertJsonPath('data.valid', false);
    }

    public function test_terminal_job_renders_read_only(): void
    {
        $job = Job::factory()->status(JobStatus::Delivered)->create();
        $session = MagicLinkSession::factory()->for($job)->create();

        $this->getJson("/api/track/{$session->token}")
            ->assertOk()
            ->assertJsonPath('data.valid', false);
    }

    public function test_instructions_and_hold_succeed_before_on_site_and_are_rejected_at_on_site(): void
    {
        [, $token] = $this->jobWithSession(JobStatus::EnRouteDrop);

        $this->postJson("/api/track/{$token}/instructions", ['body' => 'Leave at reception.'])
            ->assertOk()
            ->assertJsonPath('data.instruction', 'Leave at reception.');

        $this->postJson("/api/track/{$token}/hold", [])
            ->assertOk()
            ->assertJsonPath('data.hold.active', true);

        $this->deleteJson("/api/track/{$token}/hold")
            ->assertOk()
            ->assertJsonPath('data.hold.active', false);

        // Now on site: both are rejected.
        [, $onSiteToken] = $this->jobWithSession(JobStatus::OnSite);

        $this->postJson("/api/track/{$onSiteToken}/instructions", ['body' => 'Too late.'])
            ->assertStatus(409);

        $this->postJson("/api/track/{$onSiteToken}/hold", [])
            ->assertStatus(409);
    }

    public function test_receive_confirm_and_refuse_only_work_at_on_site(): void
    {
        [, $earlyToken] = $this->jobWithSession(JobStatus::EnRouteDrop);

        $this->postJson("/api/track/{$earlyToken}/receive-confirm")->assertStatus(409);
        $this->postJson("/api/track/{$earlyToken}/refuse", ['reason' => 'Nope'])->assertStatus(409);

        [$job, $token] = $this->jobWithSession(JobStatus::OnSite, User::factory()->rider()->create());

        $this->postJson("/api/track/{$token}/receive-confirm")->assertOk();
        $this->assertDatabaseHas('recipient_actions', ['job_id' => $job->id, 'type' => 'receive_confirm']);
    }

    public function test_refuse_sets_failed_and_blocks_a_later_rider_deliver(): void
    {
        // Past today's cutoff, so the iter-4 Exception agent raises a confirm ask
        // instead of a same-day reattempt: the job stays `failed` and blocked.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 16:00', 'America/Chicago'));
        Storage::fake('local');
        $rider = User::factory()->rider()->create();
        [$job, $token] = $this->jobWithSession(JobStatus::OnSite, $rider);

        $this->postJson("/api/track/{$token}/refuse", ['reason' => 'Wrong part ordered.'])
            ->assertOk();

        $this->assertSame(JobStatus::Failed, $job->fresh()->status);
        $this->assertDatabaseHas('recipient_actions', ['job_id' => $job->id, 'type' => 'refuse']);
        $this->assertDatabaseHas('fail_records', ['job_id' => $job->id, 'reason' => 'refused']);

        // The iter-2 rule: a failed/refused job cannot be delivered.
        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/deliver", [
                'signature' => 'data:image/png;base64,ZmFrZQ==',
            ])
            ->assertStatus(409);
    }

    public function test_action_with_an_invalid_token_is_rejected_not_a_server_error(): void
    {
        $this->postJson('/api/track/not-a-real-token/instructions', ['body' => 'x'])
            ->assertStatus(403);
    }
}

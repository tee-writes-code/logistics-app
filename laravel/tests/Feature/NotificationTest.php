<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\Job;
use App\Models\User;
use App\Services\JobStatusService;
use App\Services\MagicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function statusService(): JobStatusService
    {
        return app(JobStatusService::class);
    }

    public function test_a_transition_fans_out_in_app_rows_and_a_recipient_sms(): void
    {
        $customer = User::factory()->customer()->create();
        $rider = User::factory()->rider()->create();
        $job = Job::factory()->for($customer, 'customer')->status(JobStatus::EnRouteDrop)->create([
            'assigned_rider_id' => $rider->id,
            'is_active_for_rider' => true,
            'queue_position' => 1,
            'picked_up_at' => now()->subMinutes(5),
        ]);
        app(MagicLinkService::class)->issueFor($job);

        $this->statusService()->transition($job, JobStatus::OnSite, 'rider', ['actor_id' => $rider->id]);

        // Customer, rider, ops each get an in-app row.
        $this->assertDatabaseHas('notifications', [
            'job_id' => $job->id, 'user_id' => $customer->id, 'role' => UserRole::Customer->value, 'channel' => 'in_app',
        ]);
        $this->assertDatabaseHas('notifications', [
            'job_id' => $job->id, 'user_id' => $rider->id, 'role' => UserRole::Rider->value, 'channel' => 'in_app',
        ]);
        $this->assertDatabaseHas('notifications', [
            'job_id' => $job->id, 'user_id' => null, 'role' => UserRole::Ops->value, 'channel' => 'in_app',
        ]);

        // The recipient gets a mock SMS row carrying the magic link.
        $sms = $job->notifications()->where('channel', NotificationChannel::Sms)->first();
        $this->assertNotNull($sms);
        $this->assertNotNull($sms->magic_link);
        $this->assertStringContainsString('/#/track/', $sms->magic_link);
    }

    public function test_reaching_assigned_issues_a_magic_link(): void
    {
        $rider = User::factory()->rider()->create();
        $job = Job::factory()->status(JobStatus::Booked)->create([
            'assigned_rider_id' => $rider->id,
        ]);

        $this->assertSame(0, $job->magicLinkSession()->count());

        $this->statusService()->transition($job, JobStatus::Assigned, 'ops');

        $this->assertSame(1, $job->magicLinkSession()->whereNull('revoked_at')->count());
    }

    public function test_reaching_a_terminal_status_expires_the_magic_link(): void
    {
        Storage::fake('local');
        $rider = User::factory()->rider()->create();
        $service = app(MagicLinkService::class);
        $job = Job::factory()->status(JobStatus::OnSite)->create([
            'assigned_rider_id' => $rider->id,
            'is_active_for_rider' => true,
            'queue_position' => 1,
        ]);
        $session = $service->issueFor($job);

        $this->statusService()->transition($job, JobStatus::Delivered, 'rider', [
            'signature' => 'data:image/png;base64,ZmFrZQ==',
            'actor_id' => $rider->id,
        ]);

        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertNull($service->resolveByToken($session->token));
    }

    public function test_notifications_index_is_scoped_per_user_and_mark_read_is_per_recipient(): void
    {
        $customerA = User::factory()->customer()->create();
        $customerB = User::factory()->customer()->create();
        $jobA = Job::factory()->for($customerA, 'customer')->create();
        $jobB = Job::factory()->for($customerB, 'customer')->create();

        $mine = $jobA->notifications()->create([
            'user_id' => $customerA->id, 'role' => UserRole::Customer, 'channel' => NotificationChannel::InApp, 'message' => 'Yours.',
        ]);
        $theirs = $jobB->notifications()->create([
            'user_id' => $customerB->id, 'role' => UserRole::Customer, 'channel' => NotificationChannel::InApp, 'message' => 'Theirs.',
        ]);

        $this->actingAs($customerA)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        // Cannot mark someone else's notification read.
        $this->actingAs($customerA)
            ->postJson("/api/notifications/{$theirs->id}/read")
            ->assertStatus(403);

        $this->actingAs($customerA)
            ->postJson("/api/notifications/{$mine->id}/read")
            ->assertOk();

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_ops_sees_ops_role_in_app_rows(): void
    {
        $ops = User::factory()->ops()->create();
        $job = Job::factory()->create();
        $job->notifications()->create([
            'user_id' => null, 'role' => UserRole::Ops, 'channel' => NotificationChannel::InApp, 'message' => 'Ops row.',
        ]);

        $this->actingAs($ops)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_sms_outbox_is_ops_only(): void
    {
        $job = Job::factory()->create();
        $job->notifications()->create([
            'channel' => NotificationChannel::Sms, 'message' => 'Track it.', 'magic_link' => 'https://x/#/track/abc',
        ]);

        $this->actingAs(User::factory()->customer()->create())
            ->getJson('/api/ops/sms-outbox')
            ->assertStatus(403);

        $this->actingAs(User::factory()->ops()->create())
            ->getJson('/api/ops/sms-outbox')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channel', 'sms');
    }
}

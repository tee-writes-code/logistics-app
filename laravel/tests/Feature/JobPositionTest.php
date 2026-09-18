<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\User;
use App\Services\MagicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobPositionTest extends TestCase
{
    use RefreshDatabase;

    private function job(JobStatus $status, User $customer, ?User $rider = null): Job
    {
        return Job::factory()->for($customer, 'customer')->status($status)->create([
            'assigned_rider_id' => $rider?->id,
            'picked_up_at' => in_array($status, JobStatus::liveMapStatuses(), true) ? now()->subMinutes(10) : null,
        ]);
    }

    public function test_position_is_null_before_pickup(): void
    {
        $customer = User::factory()->customer()->create();
        $job = $this->job(JobStatus::Assigned, $customer, User::factory()->rider()->create());

        $this->actingAs($customer)
            ->getJson("/api/jobs/{$job->id}/position")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_position_is_present_within_the_live_map_window(): void
    {
        $customer = User::factory()->customer()->create();
        $job = $this->job(JobStatus::EnRouteDrop, $customer, User::factory()->rider()->create());

        $this->actingAs($customer)
            ->getJson("/api/jobs/{$job->id}/position")
            ->assertOk()
            ->assertJsonPath('data.status', 'en_route_drop')
            ->assertJsonStructure(['data' => ['current' => ['x', 'y'], 'pickup', 'drop', 'progress']]);
    }

    public function test_position_is_null_once_terminal(): void
    {
        $customer = User::factory()->customer()->create();
        $job = $this->job(JobStatus::Delivered, $customer, User::factory()->rider()->create());

        $this->actingAs($customer)
            ->getJson("/api/jobs/{$job->id}/position")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_owner_assigned_rider_and_ops_may_view_but_strangers_may_not(): void
    {
        $customer = User::factory()->customer()->create();
        $rider = User::factory()->rider()->create();
        $job = $this->job(JobStatus::EnRouteDrop, $customer, $rider);

        $this->actingAs($customer)->getJson("/api/jobs/{$job->id}/position")->assertOk();
        $this->actingAs($rider)->getJson("/api/jobs/{$job->id}/position")->assertOk();
        $this->actingAs(User::factory()->ops()->create())->getJson("/api/jobs/{$job->id}/position")->assertOk();

        // A different customer and a different rider are forbidden.
        $this->actingAs(User::factory()->customer()->create())
            ->getJson("/api/jobs/{$job->id}/position")->assertStatus(403);
        $this->actingAs(User::factory()->rider()->create())
            ->getJson("/api/jobs/{$job->id}/position")->assertStatus(403);
    }

    public function test_recipient_may_view_position_with_a_valid_token(): void
    {
        $customer = User::factory()->customer()->create();
        $job = $this->job(JobStatus::EnRouteDrop, $customer, User::factory()->rider()->create());
        $session = app(MagicLinkService::class)->issueFor($job);

        $this->getJson("/api/jobs/{$job->id}/position?token={$session->token}")
            ->assertOk()
            ->assertJsonPath('data.status', 'en_route_drop');
    }

    public function test_unauthenticated_without_a_token_is_forbidden(): void
    {
        $customer = User::factory()->customer()->create();
        $job = $this->job(JobStatus::EnRouteDrop, $customer, User::factory()->rider()->create());

        $this->getJson("/api/jobs/{$job->id}/position")->assertStatus(403);
    }
}

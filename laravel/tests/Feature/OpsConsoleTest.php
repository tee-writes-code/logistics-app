<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Agents\DispatchAgent;
use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\PickupSite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpsConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:00', 'America/Chicago'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_board_lists_all_jobs_and_filters_by_status(): void
    {
        $ops = User::factory()->ops()->create();
        Job::factory()->status(JobStatus::Booked)->create();
        Job::factory()->status(JobStatus::Delivered)->create();

        $this->actingAs($ops)->getJson('/api/ops/jobs')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($ops)->getJson('/api/ops/jobs?status=delivered')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'delivered');
    }

    public function test_board_and_console_are_ops_only(): void
    {
        $customer = User::factory()->customer()->create();
        $rider = User::factory()->rider()->create();

        $this->actingAs($customer)->getJson('/api/ops/jobs')->assertForbidden();
        $this->actingAs($rider)->getJson('/api/ops/riders')->assertForbidden();
        $this->actingAs($customer)->getJson('/api/ops/workbench')->assertForbidden();
    }

    public function test_ops_creates_a_job_on_behalf_and_dispatch_assigns(): void
    {
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $customer = User::factory()->customer()->create();
        $site = PickupSite::factory()->for($customer, 'customer')->create();

        $this->actingAs($ops)->postJson('/api/ops/jobs', [
            'customer_id' => $customer->id,
            'pickup_site_id' => $site->id,
            'drop_address' => '9 Ops Rd',
            'drop_contact_name' => 'Recipient',
            'drop_phone' => '+1-555-1234',
            'part_line' => ['name' => 'Belt', 'qty' => 1],
            'window' => 'same_day',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', JobStatus::Assigned->value)
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.assigned_rider_id', $rider->id);
    }

    public function test_ops_cannot_create_with_a_pickup_site_of_another_customer(): void
    {
        $ops = User::factory()->ops()->create();
        $customer = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $site = PickupSite::factory()->for($other, 'customer')->create();

        $this->actingAs($ops)->postJson('/api/ops/jobs', [
            'customer_id' => $customer->id,
            'pickup_site_id' => $site->id,
            'drop_address' => '9 Ops Rd',
            'drop_contact_name' => 'Recipient',
            'drop_phone' => '+1-555-1234',
            'part_line' => ['name' => 'Belt', 'qty' => 1],
            'window' => 'same_day',
        ])->assertStatus(422)->assertJsonValidationErrors('pickup_site_id');
    }

    public function test_ops_edits_on_behalf_beyond_customer_stage_gates(): void
    {
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        // Past the customer's editable stage, but Ops may still edit.
        $job = Job::factory()->assignedTo($rider)->status(JobStatus::EnRouteDrop)->create();

        $this->actingAs($ops)->patchJson("/api/ops/jobs/{$job->id}", ['instructions' => 'Leave at gate'])
            ->assertOk()
            ->assertJsonPath('data.instructions', 'Leave at gate');
    }

    public function test_ops_cancels_until_pickup_then_conflict_after(): void
    {
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();

        $booked = Job::factory()->assignedTo($rider)->status(JobStatus::Assigned)->create();
        $this->actingAs($ops)->postJson("/api/ops/jobs/{$booked->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', JobStatus::Cancelled->value);

        $pickedUp = Job::factory()->assignedTo($rider)->status(JobStatus::PickedUp)->create();
        $this->actingAs($ops)->postJson("/api/ops/jobs/{$pickedUp->id}/cancel")->assertStatus(409);
    }

    public function test_manual_assign_endpoint_moves_the_job_and_preserves_one_active(): void
    {
        $ops = User::factory()->ops()->create();
        $riderA = User::factory()->rider()->create();
        $riderB = User::factory()->rider()->create();
        $job = Job::factory()->assignedTo($riderA)->status(JobStatus::Assigned)->create(['picked_up_at' => now()]);

        $this->actingAs($ops)->postJson("/api/ops/jobs/{$job->id}/assign", ['rider_id' => $riderB->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_rider_id', $riderB->id);

        $this->assertSame(1, Job::where('assigned_rider_id', $riderB->id)->where('is_active_for_rider', true)->count());
    }

    public function test_manual_assign_after_pickup_is_rejected(): void
    {
        $ops = User::factory()->ops()->create();
        $riderA = User::factory()->rider()->create();
        $riderB = User::factory()->rider()->create();
        $job = Job::factory()->assignedTo($riderA)->status(JobStatus::PickedUp)->create();

        $this->actingAs($ops)->postJson("/api/ops/jobs/{$job->id}/assign", ['rider_id' => $riderB->id])
            ->assertStatus(409);
    }

    public function test_reorder_queue_applies_order_but_keeps_active_first(): void
    {
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $active = Job::factory()->assignedTo($rider)->status(JobStatus::EnRoutePickup)->create(['queue_position' => 0]);
        $second = Job::factory()->assignedTo($rider)->status(JobStatus::Assigned)->create(['queue_position' => 1, 'is_active_for_rider' => false]);
        $third = Job::factory()->assignedTo($rider)->status(JobStatus::Assigned)->create(['queue_position' => 2, 'is_active_for_rider' => false]);

        // Legal: active stays first, swap the two queued jobs.
        $this->actingAs($ops)->postJson("/api/ops/riders/{$rider->id}/queue/reorder", [
            'job_ids' => [$active->id, $third->id, $second->id],
        ])->assertOk();

        $this->assertSame(0, $active->refresh()->queue_position);
        $this->assertSame(1, $third->refresh()->queue_position);
        $this->assertTrue($active->is_active_for_rider);

        // Illegal: moving the in-flight active job off the front is rejected.
        $this->actingAs($ops)->postJson("/api/ops/riders/{$rider->id}/queue/reorder", [
            'job_ids' => [$second->id, $active->id, $third->id],
        ])->assertStatus(422);
    }

    public function test_rider_board_returns_riders_with_queues(): void
    {
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        Job::factory()->assignedTo($rider)->status(JobStatus::Assigned)->create();

        $this->actingAs($ops)->getJson('/api/ops/riders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $rider->id)
            ->assertJsonPath('data.0.load', 1);
    }

    public function test_workbench_summarises_agents_and_logs(): void
    {
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $job = Job::factory()->status(JobStatus::Booked)->create();
        app(DispatchAgent::class)->onBooked($job);

        $this->actingAs($ops)->getJson('/api/ops/workbench')
            ->assertOk()
            ->assertJsonPath('data.0.agent', 'dispatch');

        $this->actingAs($ops)->getJson('/api/ops/agent-logs')
            ->assertOk()
            ->assertJsonPath('data.0.agent', 'dispatch');
    }
}

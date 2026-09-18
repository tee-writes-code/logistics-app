<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_the_customers_own_jobs(): void
    {
        $customer = User::factory()->customer()->create();
        $mine = Job::factory()->for($customer, 'customer')->create();
        Job::factory()->create();

        $this->actingAs($customer)
            ->getJson('/api/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_customer_cannot_view_another_customers_job(): void
    {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->create();

        $this->actingAs($customer)
            ->getJson("/api/jobs/{$job->id}")
            ->assertForbidden();
    }

    public function test_drop_edits_succeed_while_booked(): void
    {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->for($customer, 'customer')->status(JobStatus::Booked)->create();

        $this->actingAs($customer)
            ->patchJson("/api/jobs/{$job->id}", [
                'drop_address' => 'New Address 12',
                'drop_contact_name' => 'New Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.drop_address', 'New Address 12');

        $this->assertDatabaseHas('job_timeline_events', [
            'job_id' => $job->id,
            'type' => 'edited',
        ]);
    }

    public function test_drop_edits_are_rejected_once_assigned(): void
    {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->for($customer, 'customer')->status(JobStatus::Assigned)->create();

        $this->actingAs($customer)
            ->patchJson("/api/jobs/{$job->id}", ['drop_address' => 'Too late'])
            ->assertStatus(409);
    }

    public function test_notes_edits_succeed_until_on_site(): void
    {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->for($customer, 'customer')->status(JobStatus::EnRouteDrop)->create();

        $this->actingAs($customer)
            ->patchJson("/api/jobs/{$job->id}", ['notes' => 'Ring the bell'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Ring the bell');
    }

    public function test_notes_edits_are_rejected_once_on_site(): void
    {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->for($customer, 'customer')->status(JobStatus::OnSite)->create();

        $this->actingAs($customer)
            ->patchJson("/api/jobs/{$job->id}", ['notes' => 'Too late'])
            ->assertStatus(409);
    }

    public function test_assigned_rider_cannot_edit_the_customers_notes(): void
    {
        $rider = User::factory()->rider()->create();
        $job = Job::factory()->assignedTo($rider)->status(JobStatus::EnRouteDrop)
            ->create(['notes' => 'Original note']);

        // The customer PATCH route is owner-only; the assigned rider drives the
        // lifecycle through the separate rider endpoints, not by editing fields.
        $this->actingAs($rider)
            ->patchJson("/api/jobs/{$job->id}", ['notes' => 'Rider tampered'])
            ->assertForbidden();

        $this->assertSame('Original note', $job->refresh()->notes);
    }

    public function test_cancel_succeeds_while_booked(): void
    {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->for($customer, 'customer')->status(JobStatus::Booked)->create();

        $this->actingAs($customer)
            ->postJson("/api/jobs/{$job->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', JobStatus::Cancelled->value);

        $this->assertDatabaseHas('jobs', [
            'id' => $job->id,
            'status' => JobStatus::Cancelled->value,
        ]);
        $this->assertDatabaseHas('job_timeline_events', [
            'job_id' => $job->id,
            'type' => 'cancelled',
        ]);
    }

    public function test_cancel_succeeds_while_assigned(): void
    {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->for($customer, 'customer')->status(JobStatus::Assigned)->create();

        $this->actingAs($customer)
            ->postJson("/api/jobs/{$job->id}/cancel")
            ->assertOk();
    }

    public function test_cancel_is_rejected_once_picked_up(): void
    {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->for($customer, 'customer')->status(JobStatus::PickedUp)->create();

        $this->actingAs($customer)
            ->postJson("/api/jobs/{$job->id}/cancel")
            ->assertStatus(409);

        $this->assertDatabaseHas('jobs', [
            'id' => $job->id,
            'status' => JobStatus::PickedUp->value,
        ]);
    }

    public function test_customer_cannot_cancel_another_customers_job(): void
    {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->status(JobStatus::Booked)->create();

        $this->actingAs($customer)
            ->postJson("/api/jobs/{$job->id}/cancel")
            ->assertForbidden();
    }
}

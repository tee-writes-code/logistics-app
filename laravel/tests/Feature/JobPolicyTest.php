<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_view_their_own_job(): void
    {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->for($customer, 'customer')->create();

        $this->assertTrue($customer->can('view', $job));
    }

    public function test_customer_cannot_view_another_customers_job(): void
    {
        $owner = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $job = Job::factory()->for($owner, 'customer')->create();

        $this->assertFalse($other->can('view', $job));
    }

    public function test_rider_can_view_only_jobs_assigned_to_them(): void
    {
        $assigned = User::factory()->rider()->create();
        $otherRider = User::factory()->rider()->create();
        $job = Job::factory()->assignedTo($assigned)->create();

        $this->assertTrue($assigned->can('view', $job));
        $this->assertFalse($otherRider->can('view', $job));
    }

    public function test_ops_can_view_any_job(): void
    {
        $ops = User::factory()->ops()->create();
        $job = Job::factory()->create();

        $this->assertTrue($ops->can('view', $job));
        $this->assertTrue($ops->can('delete', $job));
    }

    public function test_only_customers_can_create_jobs(): void
    {
        $this->assertTrue(User::factory()->customer()->create()->can('create', Job::class));
        $this->assertFalse(User::factory()->rider()->create()->can('create', Job::class));
    }

    public function test_visible_to_scopes_jobs_per_role(): void
    {
        $customerA = User::factory()->customer()->create();
        $customerB = User::factory()->customer()->create();
        $rider = User::factory()->rider()->create();
        $ops = User::factory()->ops()->create();

        $jobA = Job::factory()->for($customerA, 'customer')->create();
        Job::factory()->for($customerB, 'customer')->create();
        $assigned = Job::factory()->assignedTo($rider)->create();

        // 3 jobs total (jobA, jobB, and the assigned job's own customer).
        $this->assertSame([$jobA->id], Job::visibleTo($customerA)->pluck('id')->all());
        $this->assertSame([$assigned->id], Job::visibleTo($rider)->pluck('id')->all());
        $this->assertSame(3, Job::visibleTo($ops)->count());
    }
}

<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\AgentActionLog;
use App\Models\Job;
use App\Models\MagicLinkSession;
use App\Models\PartLine;
use App\Models\PickupSite;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_seeder_creates_the_expected_accounts(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertSame(1, User::where('role', UserRole::Ops)->count());
        $this->assertSame(1, User::where('role', UserRole::Customer)->count());
        $this->assertSame(5, User::where('role', UserRole::Rider)->count());

        $customer = User::where('email', 'customer@logistics.test')->firstOrFail();
        $this->assertSame(2, $customer->pickupSites()->count());
    }

    public function test_role_seeder_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame(7, User::count());
        $this->assertSame(2, PickupSite::count());
    }

    public function test_demo_seeder_creates_sample_jobs_across_every_status(): void
    {
        $this->seed();

        $this->assertSame(count(JobStatus::cases()), Job::count());

        foreach (JobStatus::cases() as $status) {
            $this->assertTrue(
                Job::where('status', $status)->exists(),
                "Expected at least one job with status {$status->value}.",
            );
        }

        // Each job has exactly one part line.
        $this->assertSame(Job::count(), PartLine::count());

        // A live recipient magic-link session and all three agents are present.
        $this->assertGreaterThanOrEqual(1, MagicLinkSession::count());
        $this->assertSame(3, AgentActionLog::distinct('agent')->count('agent'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AgentAskType;
use App\Enums\FailReason;
use App\Enums\JobStatus;
use App\Enums\NotificationChannel;
use App\Models\Job;
use App\Models\PickupSite;
use App\Models\User;
use App\Services\ClockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * iter-5 demo operator controls: the clock override (advancing offset), the
 * Ops + non-production guard, the delay/force-miss-hours triggers, the mock SMS
 * outbox, and the fixture reset.
 */
class DemoControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function monday(string $time = '09:00'): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse("2026-09-14 {$time}", 'America/Chicago'));
    }

    // --- ClockService override --------------------------------------------

    public function test_now_is_real_time_without_an_override(): void
    {
        $this->monday('09:00');

        $this->assertSame(
            CarbonImmutable::now('America/Chicago')->toIso8601String(),
            app(ClockService::class)->now()->toIso8601String(),
        );
    }

    public function test_override_is_an_advancing_offset_not_a_frozen_instant(): void
    {
        $this->monday('09:00');
        app(ClockService::class)->setOverride(
            CarbonImmutable::parse('2026-09-14 13:00', 'America/Chicago'),
            ClockService::PRESET_BEFORE_CUTOFF,
        );

        // At set time the override reads as the target instant.
        $this->assertSame('13:00', app(ClockService::class)->now()->format('H:i'));

        // Real time advances 30 minutes; the override advances with it.
        $this->monday('09:30');
        $this->assertSame('13:30', app(ClockService::class)->now()->format('H:i'));
    }

    public function test_clear_override_restores_real_time(): void
    {
        $this->monday('09:00');
        $clock = app(ClockService::class);
        $clock->setOverride(CarbonImmutable::parse('2026-09-14 16:00', 'America/Chicago'));
        $this->assertTrue(app(ClockService::class)->hasOverride());

        $clock->clearOverride();

        $this->assertFalse(app(ClockService::class)->hasOverride());
        $this->assertSame('09:00', app(ClockService::class)->now()->format('H:i'));
    }

    // --- Guard: Ops + non-production --------------------------------------

    public function test_demo_endpoints_are_forbidden_for_non_ops(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->postJson('/api/demo/clock', ['preset' => ClockService::PRESET_BEFORE_CUTOFF])
            ->assertForbidden();
    }

    public function test_demo_endpoints_require_authentication(): void
    {
        $this->postJson('/api/demo/clock', ['preset' => ClockService::PRESET_BEFORE_CUTOFF])
            ->assertUnauthorized();
    }

    public function test_demo_endpoints_are_forbidden_in_production(): void
    {
        config(['app.env' => 'production']);
        $ops = User::factory()->ops()->create();

        $this->actingAs($ops)
            ->postJson('/api/demo/clock', ['preset' => ClockService::PRESET_BEFORE_CUTOFF])
            ->assertForbidden();
    }

    // --- Clock presets flip booking --------------------------------------

    public function test_before_cutoff_preset_assigns_same_day(): void
    {
        $this->monday('09:00');
        [$ops, $customer, $site] = $this->personas();
        User::factory()->rider()->create();

        $this->actingAs($ops)
            ->postJson('/api/demo/clock', ['preset' => ClockService::PRESET_BEFORE_CUTOFF])
            ->assertOk();

        $response = $this->bookSameDay($customer, $site);
        $response->assertCreated();
        $this->assertSame(JobStatus::Assigned->value, $response->json('data.status'));
    }

    public function test_after_cutoff_preset_offers_next_only(): void
    {
        $this->monday('09:00');
        [$ops, $customer, $site] = $this->personas();
        User::factory()->rider()->create();

        $this->actingAs($ops)
            ->postJson('/api/demo/clock', ['preset' => ClockService::PRESET_AFTER_CUTOFF])
            ->assertOk();

        $response = $this->bookSameDay($customer, $site);
        $response->assertOk();
        $this->assertTrue($response->json('offer_next'));
        $this->assertDatabaseCount('jobs', 0);
    }

    // --- Triggers ---------------------------------------------------------

    public function test_trigger_delay_conflicts_on_an_ineligible_job(): void
    {
        $this->monday('09:00');
        $ops = User::factory()->ops()->create();
        $job = Job::factory()->status(JobStatus::Delivered)->create();

        $this->actingAs($ops)
            ->postJson("/api/demo/jobs/{$job->id}/delay")
            ->assertStatus(409);
    }

    public function test_trigger_unsafe_pauses_the_job_and_raises_an_ops_ask(): void
    {
        $this->monday('09:00');
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $job = Job::factory()->assignedTo($rider)->status(JobStatus::EnRouteDrop)->create();

        $this->actingAs($ops)
            ->postJson("/api/demo/jobs/{$job->id}/unsafe")
            ->assertOk();

        $this->assertFalse($job->refresh()->is_active_for_rider);
        $this->assertDatabaseHas('agent_asks', [
            'job_id' => $job->id,
            'type' => AgentAskType::Unsafe->value,
            'status' => 'pending',
        ]);
    }

    public function test_trigger_unsafe_conflicts_on_an_ineligible_job(): void
    {
        $this->monday('09:00');
        $ops = User::factory()->ops()->create();
        $job = Job::factory()->status(JobStatus::Delivered)->create();

        $this->actingAs($ops)
            ->postJson("/api/demo/jobs/{$job->id}/unsafe")
            ->assertStatus(409);
    }

    public function test_force_miss_hours_conflicts_on_a_non_failed_job(): void
    {
        $this->monday('09:00');
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $job = Job::factory()->assignedTo($rider)->status(JobStatus::OnSite)->create();

        $this->actingAs($ops)
            ->postJson("/api/demo/jobs/{$job->id}/force-miss-hours")
            ->assertStatus(409);
    }

    public function test_force_miss_hours_sets_the_clock_and_raises_the_ask(): void
    {
        $this->monday('09:00');
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        // A failed job with no ask yet (factory does not fire the hooks).
        $job = Job::factory()->assignedTo($rider)->status(JobStatus::Failed)->create();
        $job->failRecords()->create(['reason' => FailReason::Closed, 'recorded_by' => $rider->id]);

        $this->actingAs($ops)
            ->postJson("/api/demo/jobs/{$job->id}/force-miss-hours")
            ->assertOk();

        $this->assertTrue(app(ClockService::class)->hasOverride());
        $this->assertDatabaseHas('agent_asks', [
            'job_id' => $job->id,
            'type' => AgentAskType::Next->value,
            'status' => 'pending',
        ]);
    }

    // --- SMS outbox + reset ----------------------------------------------

    public function test_sms_outbox_lists_recipient_links(): void
    {
        $this->monday('09:00');
        $ops = User::factory()->ops()->create();
        $job = Job::factory()->status(JobStatus::EnRouteDrop)->create();
        $job->notifications()->create([
            'role' => null,
            'channel' => NotificationChannel::Sms,
            'message' => 'Track your delivery here.',
            'magic_link' => 'https://example.test/#/track/abc123',
        ]);

        $response = $this->actingAs($ops)->getJson('/api/demo/sms-outbox')->assertOk();

        $this->assertSame('https://example.test/#/track/abc123', $response->json('data.0.magic_link'));
    }

    public function test_reset_restores_seeded_state_and_clears_the_override(): void
    {
        $this->monday('09:00');
        $ops = User::factory()->ops()->create();
        app(ClockService::class)->setOverride(CarbonImmutable::parse('2026-09-14 16:00', 'America/Chicago'));
        Job::factory()->count(3)->create();

        $this->actingAs($ops)->postJson('/api/demo/reset')->assertOk();

        $this->assertFalse(app(ClockService::class)->hasOverride());
        // Foundation accounts are back.
        $this->assertDatabaseHas('users', ['email' => 'ops@logistics.test']);
        $this->assertDatabaseHas('users', ['email' => 'customer@logistics.test']);
        // The demo seeder creates one job per lifecycle status.
        $this->assertSame(count(JobStatus::cases()), Job::query()->count());
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @return array{0: User, 1: User, 2: PickupSite}
     */
    private function personas(): array
    {
        $ops = User::factory()->ops()->create();
        $customer = User::factory()->customer()->create();
        $site = PickupSite::factory()->for($customer, 'customer')->create();

        return [$ops, $customer, $site];
    }

    private function bookSameDay(User $customer, PickupSite $site): TestResponse
    {
        return $this->actingAs($customer)->postJson('/api/jobs', [
            'pickup_site_id' => $site->id,
            'drop_address' => '1 Main St, Springfield',
            'drop_contact_name' => 'Rex Ipient',
            'drop_phone' => '+1-555-0123',
            'part_line' => ['name' => 'Alternator', 'qty' => 1],
            'window' => 'same_day',
        ]);
    }
}

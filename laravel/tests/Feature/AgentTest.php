<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Agents\CustomerOpsAgent;
use App\Agents\DispatchAgent;
use App\Agents\ExceptionAgent;
use App\Enums\AgentAskStatus;
use App\Enums\AgentAskType;
use App\Enums\FailReason;
use App\Enums\JobStatus;
use App\Enums\JobWindow;
use App\Models\AgentAsk;
use App\Models\Job;
use App\Models\PickupSite;
use App\Models\User;
use App\Services\JobStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function beforeCutoff(): void
    {
        // Monday, well within hours and before the 14:00 cutoff.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:00', 'America/Chicago'));
    }

    private function afterCutoff(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 16:00', 'America/Chicago'));
    }

    private function assignedJob(User $rider, JobStatus $status): Job
    {
        return Job::factory()->assignedTo($rider)->status($status)->create(['picked_up_at' => now()]);
    }

    // --- Dispatch ---------------------------------------------------------

    public function test_dispatch_on_booked_assigns_a_rider_with_eta_and_queue_position(): void
    {
        $this->beforeCutoff();
        $rider = User::factory()->rider()->create();
        $job = Job::factory()->status(JobStatus::Booked)->create();

        app(DispatchAgent::class)->onBooked($job);
        $job->refresh();

        $this->assertSame(JobStatus::Assigned, $job->status);
        $this->assertSame($rider->id, $job->assigned_rider_id);
        $this->assertSame(0, $job->queue_position);
        $this->assertTrue($job->is_active_for_rider);
        // ETA = now + sum(leg_minutes) = 09:00 + 105 minutes = 10:45 (wall clock).
        $this->assertNotNull($job->eta_at);
        $this->assertSame('10:45', $job->eta_at->format('H:i'));
        $this->assertDatabaseHas('agent_action_logs', ['job_id' => $job->id, 'agent' => 'dispatch']);
    }

    public function test_dispatch_picks_the_least_loaded_rider_then_lowest_id(): void
    {
        $this->beforeCutoff();
        $busy = User::factory()->rider()->create();
        $free = User::factory()->rider()->create();
        Job::factory()->assignedTo($busy)->status(JobStatus::Assigned)->create();

        $job = Job::factory()->status(JobStatus::Booked)->create();
        app(DispatchAgent::class)->onBooked($job);

        $this->assertSame($free->id, $job->refresh()->assigned_rider_id);
    }

    public function test_offer_next_after_cutoff_creates_nothing_and_never_assigns(): void
    {
        $this->afterCutoff();
        User::factory()->rider()->create();
        $customer = User::factory()->customer()->create();
        $site = PickupSite::factory()->for($customer, 'customer')->create();

        $this->actingAs($customer)->postJson('/api/jobs', [
            'pickup_site_id' => $site->id,
            'drop_address' => '1 A St',
            'drop_contact_name' => 'R',
            'drop_phone' => '+1-555-0000',
            'part_line' => ['name' => 'Part', 'qty' => 1],
            'window' => 'same_day',
        ])->assertOk()->assertJsonPath('offer_next', true);

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('agent_action_logs', 0);
    }

    public function test_manual_assign_overrides_dispatch_and_recomputes(): void
    {
        $this->beforeCutoff();
        $riderA = User::factory()->rider()->create();
        $riderB = User::factory()->rider()->create();
        $job = $this->assignedJob($riderA, JobStatus::Assigned);

        app(DispatchAgent::class)->assign($job, $riderB, 'ops');
        $job->refresh();

        $this->assertSame($riderB->id, $job->assigned_rider_id);
        $this->assertTrue($job->is_active_for_rider);
        $this->assertSame(0, $job->queue_position);
        // Old rider no longer holds it active.
        $this->assertSame(0, Job::where('assigned_rider_id', $riderA->id)->where('is_active_for_rider', true)->count());
    }

    // --- Exception: delay -------------------------------------------------

    public function test_delay_reassigns_to_another_rider_without_confirm(): void
    {
        $this->beforeCutoff();
        $riderA = User::factory()->rider()->create();
        $riderB = User::factory()->rider()->create();
        $job = $this->assignedJob($riderA, JobStatus::Assigned);

        app(ExceptionAgent::class)->onDelay($job);
        $job->refresh();

        $this->assertSame($riderB->id, $job->assigned_rider_id);
        $this->assertSame(0, Job::where('assigned_rider_id', $riderA->id)->whereNotIn('status', ['delivered', 'returned', 'cancelled'])->count());
        $this->assertDatabaseHas('agent_action_logs', ['job_id' => $job->id, 'agent' => 'exception']);
        $this->assertDatabaseCount('agent_asks', 0);
    }

    // --- Exception: fail / refuse ----------------------------------------

    public function test_rider_fail_via_endpoint_reattempts_when_hours_fit(): void
    {
        $this->beforeCutoff();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::OnSite);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/fail", ['reason' => FailReason::Closed->value])
            ->assertOk();

        $this->assertSame(JobStatus::EnRouteDrop, $job->refresh()->status);
        $this->assertDatabaseCount('agent_asks', 0);
        $this->assertDatabaseHas('agent_action_logs', ['job_id' => $job->id, 'agent' => 'exception']);
    }

    public function test_rider_fail_without_hours_raises_a_next_ask_and_keeps_failed(): void
    {
        $this->afterCutoff();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::OnSite);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/fail", ['reason' => FailReason::Closed->value])
            ->assertOk();

        $this->assertSame(JobStatus::Failed, $job->refresh()->status);
        $this->assertDatabaseHas('agent_asks', [
            'job_id' => $job->id,
            'type' => AgentAskType::Next->value,
            'status' => AgentAskStatus::Pending->value,
        ]);
    }

    public function test_refuse_without_hours_raises_a_return_ask(): void
    {
        $this->afterCutoff();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::OnSite);

        app(ExceptionAgent::class)->onRefuse($job->fresh());

        $this->assertDatabaseHas('agent_asks', [
            'job_id' => $job->id,
            'type' => AgentAskType::Return->value,
            'status' => AgentAskStatus::Pending->value,
        ]);
    }

    // --- Exception: unsafe ------------------------------------------------

    public function test_unsafe_pauses_the_job_and_raises_an_ops_ask(): void
    {
        $this->beforeCutoff();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::EnRouteDrop);

        app(ExceptionAgent::class)->onUnsafe($job->fresh());
        $job->refresh();

        $this->assertFalse($job->is_active_for_rider);
        $this->assertDatabaseHas('agent_asks', [
            'job_id' => $job->id,
            'type' => AgentAskType::Unsafe->value,
            'status' => AgentAskStatus::Pending->value,
        ]);
    }

    public function test_recompute_puts_the_in_flight_job_at_the_head_even_when_queued_behind_assigned_jobs(): void
    {
        $this->beforeCutoff();
        $rider = User::factory()->rider()->create();

        // Two assigned jobs are queued ahead; the in-flight job sits at the back
        // of the queue. After recompute the in-flight job must lead (position 0,
        // active) and the assigned jobs must follow in their prior queue order.
        $assignedFirst = Job::factory()->assignedTo($rider)->status(JobStatus::Assigned)->create([
            'queue_position' => 0,
            'is_active_for_rider' => true,
        ]);
        $assignedSecond = Job::factory()->assignedTo($rider)->status(JobStatus::Assigned)->create([
            'queue_position' => 1,
            'is_active_for_rider' => false,
        ]);
        $inFlight = Job::factory()->assignedTo($rider)->status(JobStatus::EnRouteDrop)->create([
            'picked_up_at' => now(),
            'queue_position' => 2,
            'is_active_for_rider' => false,
        ]);

        app(DispatchAgent::class)->recompute($rider);

        // The in-flight job leads and is the sole active job.
        $this->assertSame(0, $inFlight->refresh()->queue_position);
        $this->assertTrue($inFlight->is_active_for_rider);

        // The assigned jobs follow deterministically by their prior queue order.
        $this->assertSame(1, $assignedFirst->refresh()->queue_position);
        $this->assertFalse($assignedFirst->is_active_for_rider);
        $this->assertSame(2, $assignedSecond->refresh()->queue_position);
        $this->assertFalse($assignedSecond->is_active_for_rider);

        // Exactly one active job for the rider.
        $this->assertSame(1, Job::where('assigned_rider_id', $rider->id)
            ->where('is_active_for_rider', true)->count());
    }

    public function test_unsafe_pause_promotes_the_riders_next_job_to_active(): void
    {
        $this->beforeCutoff();
        $rider = User::factory()->rider()->create();
        // A is in-flight and active at the head; B is queued behind it.
        $jobA = $this->assignedJob($rider, JobStatus::EnRouteDrop);
        $jobA->update(['queue_position' => 0, 'is_active_for_rider' => true]);
        $jobB = Job::factory()->assignedTo($rider)->status(JobStatus::Assigned)->create([
            'queue_position' => 1,
            'is_active_for_rider' => false,
        ]);

        app(ExceptionAgent::class)->onUnsafe($jobA->fresh());

        // A is paused; B is promoted into the single active slot immediately.
        $this->assertFalse($jobA->refresh()->is_active_for_rider);
        $this->assertTrue($jobB->refresh()->is_active_for_rider);
    }

    public function test_rejecting_an_unsafe_ask_keeps_the_job_paused_and_never_resumes_it(): void
    {
        $this->beforeCutoff();
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $jobA = $this->assignedJob($rider, JobStatus::EnRouteDrop);
        $jobA->update(['queue_position' => 0, 'is_active_for_rider' => true]);
        $jobB = Job::factory()->assignedTo($rider)->status(JobStatus::Assigned)->create([
            'queue_position' => 1,
            'is_active_for_rider' => false,
        ]);

        app(ExceptionAgent::class)->onUnsafe($jobA->fresh());
        $ask = AgentAsk::where('job_id', $jobA->id)->where('type', AgentAskType::Unsafe->value)->firstOrFail();

        $this->actingAs($ops)->postJson("/api/agent-asks/{$ask->id}/reject")->assertOk();

        // Unsafe reject keeps the ask pending so the pause holds; only a confirm resumes.
        $this->assertSame(AgentAskStatus::Pending, $ask->refresh()->status);
        $this->assertFalse($jobA->refresh()->is_active_for_rider);
        $this->assertTrue($jobB->refresh()->is_active_for_rider);

        // The paused job cannot be driven by the rider (409, not the active job).
        $this->actingAs($rider)
            ->postJson("/api/jobs/{$jobA->id}/arrive-site")
            ->assertStatus(409);

        // A later recompute (a new booking, a delivery elsewhere) never resumes A.
        app(DispatchAgent::class)->recompute($rider);
        $this->assertFalse($jobA->refresh()->is_active_for_rider);
        $this->assertTrue($jobB->refresh()->is_active_for_rider);
    }

    public function test_a_sibling_delivered_does_not_reactivate_a_paused_job(): void
    {
        $this->beforeCutoff();
        Storage::fake('local');
        $rider = User::factory()->rider()->create();
        // A is in-flight and active at the head; B is progressing behind it.
        $jobA = $this->assignedJob($rider, JobStatus::EnRouteDrop);
        $jobA->update(['queue_position' => 0, 'is_active_for_rider' => true]);
        $jobB = Job::factory()->assignedTo($rider)->status(JobStatus::OnSite)->create([
            'picked_up_at' => now(),
            'queue_position' => 1,
            'is_active_for_rider' => false,
        ]);

        // Unsafe pauses A and promotes B into the single active slot.
        app(ExceptionAgent::class)->onUnsafe($jobA->fresh());
        $this->assertFalse($jobA->refresh()->is_active_for_rider);
        $this->assertTrue($jobB->refresh()->is_active_for_rider);

        // B is delivered (terminal). The promote must NOT hand the active slot
        // back to the still-paused A.
        app(JobStatusService::class)->transition($jobB->fresh(), JobStatus::Delivered, 'rider', [
            'signature' => 'data:image/png;base64,ZmFrZS1zaWduYXR1cmU=',
        ]);

        $jobA->refresh();
        $this->assertFalse($jobA->is_active_for_rider);
        // The surviving job closes the gap the terminal job left: no gap at 0.
        $this->assertSame(0, $jobA->queue_position);

        // The paused job cannot be driven by the rider.
        $this->actingAs($rider)
            ->postJson("/api/jobs/{$jobA->id}/arrive-site")
            ->assertStatus(409);
    }

    public function test_a_sibling_cancelled_does_not_reactivate_a_paused_job(): void
    {
        $this->beforeCutoff();
        $rider = User::factory()->rider()->create();
        $jobA = $this->assignedJob($rider, JobStatus::EnRouteDrop);
        $jobA->update(['queue_position' => 0, 'is_active_for_rider' => true]);
        $jobB = Job::factory()->assignedTo($rider)->status(JobStatus::Assigned)->create([
            'queue_position' => 1,
            'is_active_for_rider' => false,
        ]);

        app(ExceptionAgent::class)->onUnsafe($jobA->fresh());
        $this->assertTrue($jobB->refresh()->is_active_for_rider);

        // B is cancelled (terminal). A must stay paused, not become active.
        app(JobStatusService::class)->transition($jobB->fresh(), JobStatus::Cancelled, 'ops');

        $jobA->refresh();
        $this->assertFalse($jobA->is_active_for_rider);
        $this->assertSame(0, $jobA->queue_position);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$jobA->id}/arrive-site")
            ->assertStatus(409);
    }

    public function test_a_paused_job_flagged_active_still_cannot_be_driven(): void
    {
        $this->beforeCutoff();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::EnRouteDrop);
        // Force the normally-impossible state: paused yet flagged active. The
        // rider-path guard must still refuse to drive it.
        $job->update(['queue_position' => 0, 'is_active_for_rider' => true]);
        AgentAsk::factory()
            ->type(AgentAskType::Unsafe)
            ->status(AgentAskStatus::Pending)
            ->create(['job_id' => $job->id]);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/arrive-site")
            ->assertStatus(409);
    }

    // --- Confirm / reject -------------------------------------------------

    public function test_confirm_next_reschedules_to_next_window_and_reassigns(): void
    {
        $this->afterCutoff();
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::OnSite);
        app(ExceptionAgent::class)->onFail(app(JobStatusService::class)->transition($job, JobStatus::Failed, 'rider', ['reason' => FailReason::Closed]));
        $ask = AgentAsk::where('job_id', $job->id)->firstOrFail();

        $this->actingAs($ops)
            ->postJson("/api/agent-asks/{$ask->id}/confirm", ['decision' => 'next'])
            ->assertOk();

        $job->refresh();
        $this->assertSame(JobWindow::Next, $job->window);
        // Reassigned for the next window (a rider is available).
        $this->assertSame(JobStatus::Assigned, $job->status);
        $this->assertSame(AgentAskStatus::Confirmed, $ask->refresh()->status);
    }

    public function test_confirm_return_moves_to_returning(): void
    {
        $this->afterCutoff();
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::OnSite);
        app(JobStatusService::class)->transition($job, JobStatus::Failed, 'recipient', ['reason' => FailReason::Refused]);
        $ask = AgentAsk::where('job_id', $job->id)->firstOrFail();
        $this->assertSame(AgentAskType::Return, $ask->type);

        $this->actingAs($ops)
            ->postJson("/api/agent-asks/{$ask->id}/confirm")
            ->assertOk();

        $this->assertSame(JobStatus::Returning, $job->refresh()->status);
    }

    public function test_reject_leaves_a_miss_hours_ask_open(): void
    {
        $this->afterCutoff();
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::OnSite);
        app(JobStatusService::class)->transition($job, JobStatus::Failed, 'rider', ['reason' => FailReason::Closed]);
        $ask = AgentAsk::where('job_id', $job->id)->firstOrFail();

        $this->actingAs($ops)
            ->postJson("/api/agent-asks/{$ask->id}/reject")
            ->assertOk();

        $this->assertSame(AgentAskStatus::Pending, $ask->refresh()->status);
        $this->assertSame(JobStatus::Failed, $job->refresh()->status);
    }

    public function test_confirm_race_returns_409_on_the_second_resolver(): void
    {
        $this->afterCutoff();
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::OnSite);
        app(JobStatusService::class)->transition($job, JobStatus::Failed, 'recipient', ['reason' => FailReason::Refused]);
        $ask = AgentAsk::where('job_id', $job->id)->firstOrFail();

        $this->actingAs($ops)->postJson("/api/agent-asks/{$ask->id}/confirm")->assertOk();
        $this->actingAs($ops)->postJson("/api/agent-asks/{$ask->id}/confirm")->assertStatus(409);
    }

    public function test_confirm_reattempt_that_no_longer_fits_is_rejected_and_stays_pending(): void
    {
        $this->afterCutoff();
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::OnSite);
        app(JobStatusService::class)->transition($job, JobStatus::Failed, 'rider', ['reason' => FailReason::Closed]);
        $ask = AgentAsk::where('job_id', $job->id)->firstOrFail();

        // Still after cutoff, so a same-day reattempt no longer fits.
        $this->actingAs($ops)
            ->postJson("/api/agent-asks/{$ask->id}/confirm", ['decision' => 'reattempt'])
            ->assertStatus(409);

        $this->assertSame(AgentAskStatus::Pending, $ask->refresh()->status);
        $this->assertSame(JobStatus::Failed, $job->refresh()->status);
    }

    public function test_owning_customer_can_confirm_but_a_stranger_cannot(): void
    {
        $this->afterCutoff();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::OnSite);
        $owner = User::query()->find($job->customer_id);
        $stranger = User::factory()->customer()->create();
        app(JobStatusService::class)->transition($job, JobStatus::Failed, 'recipient', ['reason' => FailReason::Refused]);
        $ask = AgentAsk::where('job_id', $job->id)->firstOrFail();

        $this->actingAs($stranger)->postJson("/api/agent-asks/{$ask->id}/confirm")->assertForbidden();
        $this->actingAs($owner)->postJson("/api/agent-asks/{$ask->id}/confirm")->assertOk();
    }

    // --- Customer-ops fan-out + no double notify --------------------------

    public function test_assignment_emits_exactly_one_templated_notification_set(): void
    {
        $this->beforeCutoff();
        $rider = User::factory()->rider()->create();
        $job = Job::factory()->status(JobStatus::Booked)->create();

        app(DispatchAgent::class)->onBooked($job);

        // customer + rider + ops (in-app) + recipient (sms) = 4, emitted once.
        $this->assertSame(4, $job->notifications()->count());
        $this->assertDatabaseHas('notifications', [
            'job_id' => $job->id,
            'role' => 'customer',
            'message' => 'Job #'.$job->id.': a rider has been assigned and is scheduled to collect the part.',
        ]);
        // Plan-change timeline note posted once by the Customer-ops agent.
        $this->assertDatabaseHas('job_timeline_events', ['job_id' => $job->id, 'type' => 'plan_change']);
    }

    public function test_customer_ops_status_line_hides_location_before_pickup(): void
    {
        $rider = User::factory()->rider()->create();
        $assigned = $this->assignedJob($rider, JobStatus::Assigned);
        $assigned->update(['picked_up_at' => null]);

        $line = app(CustomerOpsAgent::class)->answerStatus($assigned->fresh());

        $this->assertStringNotContainsStringIgnoringCase('en route', $line);
        $this->assertStringContainsString('rider assigned', strtolower($line));
    }

    // --- Regression: #1 next-window reassignment recomputes the OLD rider -----

    public function test_confirm_next_recomputes_the_old_rider_so_their_next_job_activates(): void
    {
        $this->afterCutoff();
        $ops = User::factory()->ops()->create();
        $riderA = User::factory()->rider()->create();
        $riderB = User::factory()->rider()->create();

        // Rider A holds an active in-flight job plus a queued one behind it.
        $j1 = Job::factory()->assignedTo($riderA)->status(JobStatus::OnSite)
            ->create(['picked_up_at' => now(), 'queue_position' => 0, 'is_active_for_rider' => true]);
        $j2 = Job::factory()->assignedTo($riderA)->status(JobStatus::Assigned)
            ->create(['queue_position' => 1, 'is_active_for_rider' => false]);

        // J1 fails after cutoff -> Next ask; confirm reschedules it to the next window.
        app(JobStatusService::class)->transition($j1, JobStatus::Failed, 'rider', ['reason' => FailReason::Closed]);
        $ask = AgentAsk::where('job_id', $j1->id)->firstOrFail();

        $this->actingAs($ops)
            ->postJson("/api/agent-asks/{$ask->id}/confirm", ['decision' => 'next'])
            ->assertOk();

        // J1 moved to the other rider.
        $this->assertSame($riderB->id, $j1->refresh()->assigned_rider_id);

        // The old rider was recomputed: J2 becomes active at position 0 (no gap),
        // and Rider A has exactly one active job.
        $j2->refresh();
        $this->assertTrue($j2->is_active_for_rider);
        $this->assertSame(0, $j2->queue_position);
        $this->assertSame(1, Job::where('assigned_rider_id', $riderA->id)
            ->where('is_active_for_rider', true)->count());
    }

    // --- Regression: #3 a delay resets an in-flight job to `assigned` ----------

    public function test_delay_resets_an_in_flight_job_to_assigned_for_the_new_rider(): void
    {
        $this->beforeCutoff();
        $riderA = User::factory()->rider()->create();
        $riderB = User::factory()->rider()->create();
        // In-flight (picked up) on rider A.
        $job = $this->assignedJob($riderA, JobStatus::PickedUp);

        app(ExceptionAgent::class)->onDelay($job->fresh());
        $job->refresh();

        $this->assertSame($riderB->id, $job->assigned_rider_id);
        // The new rider starts fresh: the progressed status and pickup timestamp are cleared.
        $this->assertSame(JobStatus::Assigned, $job->status);
        $this->assertNull($job->picked_up_at);
        $this->assertTrue($job->is_active_for_rider);
        $this->assertSame(0, $job->queue_position);
    }

    // --- Regression: #4 unsafe pause survives recompute, then resumes ----------

    public function test_unsafe_pause_survives_recompute_and_resumes_on_confirm(): void
    {
        $this->beforeCutoff();
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::EnRouteDrop);

        app(ExceptionAgent::class)->onUnsafe($job->fresh());
        $this->assertFalse($job->refresh()->is_active_for_rider);
        $ask = AgentAsk::where('job_id', $job->id)->where('type', AgentAskType::Unsafe->value)->firstOrFail();

        // A later recompute must NOT re-activate the paused job.
        app(DispatchAgent::class)->recompute($rider);
        $this->assertFalse($job->refresh()->is_active_for_rider);

        // Resolving the unsafe ask resumes the job to its active slot.
        $this->actingAs($ops)->postJson("/api/agent-asks/{$ask->id}/confirm")->assertOk();
        $this->assertTrue($job->refresh()->is_active_for_rider);
        $this->assertSame(AgentAskStatus::Confirmed, $ask->refresh()->status);
    }

    // --- Regression: #5 confirming a hold-origin `next` ask reschedules --------

    public function test_confirm_hold_next_reschedules_to_next_window_and_notifies(): void
    {
        $this->afterCutoff();
        $ops = User::factory()->ops()->create();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::EnRouteDrop);

        // A hold that misses today's hours raises a `next` ask with origin=hold.
        app(ExceptionAgent::class)->onHoldMissesHours($job->fresh());
        $ask = AgentAsk::where('job_id', $job->id)->firstOrFail();
        $this->assertSame(AgentAskType::Next, $ask->type);
        $this->assertSame('hold', $ask->payload['origin']);

        $notificationsBefore = $job->notifications()->count();

        $this->actingAs($ops)->postJson("/api/agent-asks/{$ask->id}/confirm")->assertOk();

        $job->refresh();
        // The reschedule actually happened (not just a timeline note): window flipped
        // to Next, the job re-dispatched, and a notification set fired.
        $this->assertSame(JobWindow::Next, $job->window);
        $this->assertContains($job->status, [JobStatus::Booked, JobStatus::Assigned]);
        $this->assertGreaterThan($notificationsBefore, $job->notifications()->count());
    }

    // --- Regression: #2 JobTransitioned defers to the outer commit ------------

    public function test_job_transitioned_defers_dispatch_until_the_outer_transaction_commits(): void
    {
        $this->beforeCutoff();
        $rider = User::factory()->rider()->create();
        $job = $this->assignedJob($rider, JobStatus::AtPickup);

        DB::transaction(function () use ($job): void {
            app(JobStatusService::class)->transition($job, JobStatus::PickedUp, 'rider');

            // While the outer transaction is still open, the listeners have NOT run
            // (the event is deferred via ShouldDispatchAfterCommit).
            $this->assertSame(0, $job->notifications()->count());
        });

        // After the outer transaction commits, the notification listener has run.
        $this->assertGreaterThan(0, $job->notifications()->count());
    }
}

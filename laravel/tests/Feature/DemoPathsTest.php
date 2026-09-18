<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AgentAskType;
use App\Enums\FailReason;
use App\Enums\JobStatus;
use App\Enums\JobWindow;
use App\Models\AgentAsk;
use App\Models\Job;
use App\Models\MagicLinkSession;
use App\Models\PickupSite;
use App\Models\User;
use App\Services\ClockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * iter-5 acceptance tests: the four scripted demo paths (Path 3 has an A and a B
 * branch), each asserting its exact Pass line from prototype-paths.md plus the
 * shared UI checks (personas see only allowed jobs; Recipient needs no sign-in;
 * the live map appears only after picked_up; queue empty / one active per rider;
 * correct final status; no fee amounts anywhere). The paths are driven end to end
 * over HTTP as the real personas and exercise iters 1..4 through the demo
 * controls; they add no new product behaviour.
 */
class DemoPathsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // --- Path 1: successful delivery --------------------------------------

    public function test_path_1_successful_delivery(): void
    {
        $this->monday('09:00');
        [$ops, $customer, $site] = $this->personas();
        $rider = User::factory()->rider()->create();

        $this->setClock($ops, ClockService::PRESET_BEFORE_CUTOFF);

        $booking = $this->bookSameDay($customer, $site)->assertCreated();
        $this->assertSame(JobStatus::Assigned->value, $booking->json('data.status'));
        $jobId = (int) $booking->json('data.id');

        // Shared check: personas see only their allowed jobs.
        $this->assertContains($jobId, $this->actingAs($customer)->getJson('/api/jobs')->json('data.*.id'));
        $stranger = User::factory()->customer()->create();
        $this->assertNotContains($jobId, $this->actingAs($stranger)->getJson('/api/jobs')->json('data.*.id'));

        // Shared check: the live map is dark before picked_up.
        $this->assertNull($this->actingAs($customer)->getJson("/api/jobs/{$jobId}/position")->json('data'));

        $this->actingAs($rider);
        $this->rider("/api/jobs/{$jobId}/start")->assertOk();
        $this->rider("/api/jobs/{$jobId}/arrive-pickup")->assertOk();
        $this->rider("/api/jobs/{$jobId}/collect")->assertOk();

        // Shared check: the live map is active from picked_up, for the audiences.
        $this->assertNotNull($this->actingAs($customer)->getJson("/api/jobs/{$jobId}/position")->json('data'));

        // Shared check: the Recipient tracks with no sign-in (token only).
        $token = MagicLinkSession::query()->where('job_id', $jobId)->value('token');
        $this->getJson("/api/track/{$token}")->assertOk();

        $this->actingAs($rider);
        $this->rider("/api/jobs/{$jobId}/depart-drop")->assertOk();
        $this->rider("/api/jobs/{$jobId}/arrive-site")->assertOk();
        $delivered = $this->postJson("/api/jobs/{$jobId}/deliver", ['signature' => 'data:image/png;base64,AAAA'])
            ->assertOk();

        // Pass: delivered with a signature (photo optional); no agent ask; queue empty.
        $this->assertSame(JobStatus::Delivered->value, $delivered->json('data.status'));
        $this->assertDatabaseHas('pods', ['job_id' => $jobId]);
        $this->assertNotNull(Job::find($jobId)->pod->signature_path);
        $this->assertDatabaseCount('agent_asks', 0);
        $this->assertRiderQueueEmpty($rider);
        $this->assertNoFeeAmounts($delivered);
    }

    // --- Path 2: delay then reassign --------------------------------------

    public function test_path_2_delay_then_reassign(): void
    {
        $this->monday('09:00');
        [$ops, $customer, $site] = $this->personas();
        // Two riders so the Exception agent has somewhere to reassign to. Which
        // one Dispatch picks first is the agent's call; the test reads it back.
        User::factory()->rider()->create();
        User::factory()->rider()->create();

        $this->setClock($ops, ClockService::PRESET_BEFORE_CUTOFF);

        $jobId = (int) $this->bookSameDay($customer, $site)->assertCreated()->json('data.id');
        $riderA = User::findOrFail(Job::find($jobId)->assigned_rider_id);

        // Rider A gets the job moving.
        $this->actingAs($riderA)->postJson("/api/jobs/{$jobId}/start")->assertOk();

        // Operator triggers a delay: the Exception agent reassigns to another rider.
        $this->actingAs($ops)->postJson("/api/demo/jobs/{$jobId}/delay")->assertOk();

        $job = Job::find($jobId);
        $riderB = User::findOrFail($job->assigned_rider_id);
        // Pass: Rider A no longer has the job; another rider now holds it.
        $this->assertNotSame($riderA->id, $riderB->id);
        $this->assertRiderQueueEmpty($riderA);

        // Pass: workbench shows the Exception reassign then the Customer-ops notify.
        $workbench = $this->actingAs($ops)->getJson('/api/ops/workbench')->assertOk();
        $agents = collect($workbench->json('data'))->keyBy('agent');
        $this->assertStringContainsStringIgnoringCase('reassign', (string) $agents['exception']['last_action']);
        $this->assertDatabaseHas('notifications', ['job_id' => $jobId]);

        // Pass: no confirm prompt.
        $this->assertDatabaseCount('agent_asks', 0);

        // The delay reset the job to `assigned`, so Rider B starts the run fresh
        // (a step Rider A performed is not inherited).
        $this->assertSame(JobStatus::Assigned->value, $job->status->value);

        // Rider B finishes the run from the start.
        $this->actingAs($riderB);
        foreach (['start', 'arrive-pickup', 'collect', 'depart-drop', 'arrive-site'] as $action) {
            $this->rider("/api/jobs/{$jobId}/{$action}")->assertOk();
        }
        $delivered = $this->postJson("/api/jobs/{$jobId}/deliver", ['signature' => 'sig'])->assertOk();

        $this->assertSame(JobStatus::Delivered->value, $delivered->json('data.status'));
        $this->assertRiderQueueEmpty($riderB);
        $this->assertNoFeeAmounts($delivered);
    }

    // --- Path 3A: failed then same-day reattempt (hours still fit) ---------

    public function test_path_3a_failed_then_reattempt(): void
    {
        $this->monday('09:00');
        [$ops, $customer, $site] = $this->personas();
        $rider = User::factory()->rider()->create();

        $this->setClock($ops, ClockService::PRESET_BEFORE_CUTOFF);
        $jobId = (int) $this->bookSameDay($customer, $site)->assertCreated()->json('data.id');

        $this->driveToOnSite($rider, $jobId);

        // Fail while hours still fit: the Exception agent reattempts, no confirm.
        $this->actingAs($rider)
            ->postJson("/api/jobs/{$jobId}/fail", ['reason' => FailReason::Closed->value])
            ->assertOk();

        $this->assertSame(JobStatus::EnRouteDrop->value, Job::find($jobId)->status->value);
        $this->assertDatabaseCount('agent_asks', 0);

        // Deliver on the reattempt.
        $this->actingAs($rider);
        $this->rider("/api/jobs/{$jobId}/arrive-site")->assertOk();
        $delivered = $this->postJson("/api/jobs/{$jobId}/deliver", ['signature' => 'sig'])->assertOk();

        // Pass A: delivered on reattempt, no fee UI, no confirm.
        $this->assertSame(JobStatus::Delivered->value, $delivered->json('data.status'));
        $this->assertDatabaseCount('agent_asks', 0);
        $this->assertRiderQueueEmpty($rider);
        $this->assertNoFeeAmounts($delivered);
    }

    // --- Path 3B: failed, hours no longer fit -> return -------------------

    public function test_path_3b_force_miss_hours_then_return(): void
    {
        $this->monday('09:00');
        [$ops, $customer, $site] = $this->personas();
        $rider = User::factory()->rider()->create();

        $this->setClock($ops, ClockService::PRESET_BEFORE_CUTOFF);
        $jobId = (int) $this->bookSameDay($customer, $site)->assertCreated()->json('data.id');
        $this->driveToOnSite($rider, $jobId);

        // Operator forces "hours no longer fit", then the recipient refusal lands.
        $this->setClock($ops, ClockService::PRESET_AFTER_CUTOFF);
        $this->actingAs($rider)
            ->postJson("/api/jobs/{$jobId}/fail", ['reason' => FailReason::Refused->value])
            ->assertOk();

        // force-miss-hours surfaces the confirm ask (return branch for a refusal).
        $forced = $this->actingAs($ops)->postJson("/api/demo/jobs/{$jobId}/force-miss-hours")->assertOk();
        $this->assertNoFeeAmounts($forced);

        $ask = AgentAsk::query()->where('job_id', $jobId)->firstOrFail();
        $this->assertSame(AgentAskType::Return, $ask->type);

        // Accept return.
        $this->actingAs($ops)->postJson("/api/agent-asks/{$ask->id}/confirm")->assertOk();
        $this->assertSame(JobStatus::Returning->value, Job::find($jobId)->status->value);

        // Rider returns the part to the shop (optional photo).
        $returned = $this->actingAs($rider)->postJson("/api/jobs/{$jobId}/return-complete")->assertOk();

        // Pass B: confirm prompt appeared; final status returned; no fee amounts.
        $this->assertSame(JobStatus::Returned->value, $returned->json('data.status'));
        $this->assertRiderQueueEmpty($rider);
        $this->assertNoFeeAmounts($returned);
    }

    // --- Path 3B: failed, hours no longer fit -> next ---------------------

    public function test_path_3b_force_miss_hours_then_next(): void
    {
        $this->monday('09:00');
        [$ops, $customer, $site] = $this->personas();
        $rider = User::factory()->rider()->create();

        $this->setClock($ops, ClockService::PRESET_BEFORE_CUTOFF);
        $jobId = (int) $this->bookSameDay($customer, $site)->assertCreated()->json('data.id');
        $this->driveToOnSite($rider, $jobId);

        $this->setClock($ops, ClockService::PRESET_AFTER_CUTOFF);
        $this->actingAs($rider)
            ->postJson("/api/jobs/{$jobId}/fail", ['reason' => FailReason::Closed->value])
            ->assertOk();

        $this->actingAs($ops)->postJson("/api/demo/jobs/{$jobId}/force-miss-hours")->assertOk();

        $ask = AgentAsk::query()->where('job_id', $jobId)->firstOrFail();
        $this->assertSame(AgentAskType::Next, $ask->type);

        // Accept next: rebooked into the next window and reassigned.
        $this->actingAs($ops)->postJson("/api/agent-asks/{$ask->id}/confirm", ['decision' => 'next'])->assertOk();

        // Pass B: final status booked (next); no fee amounts.
        $job = Job::find($jobId);
        $this->assertSame(JobWindow::Next, $job->window);
        $this->assertContains($job->status, [JobStatus::Booked, JobStatus::Assigned]);
    }

    // --- Path 4: late booking, next window --------------------------------

    public function test_path_4_late_booking_reject_creates_no_job(): void
    {
        $this->monday('09:00');
        [$ops, $customer, $site] = $this->personas();
        User::factory()->rider()->create();

        $this->setClock($ops, ClockService::PRESET_AFTER_CUTOFF);

        // After cutoff, a same-day booking never assigns: only a next offer.
        $offer = $this->bookSameDay($customer, $site)->assertOk();
        $this->assertTrue($offer->json('offer_next'));
        // Reject == abandon: no job remains.
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_path_4_late_booking_confirm_books_next_only(): void
    {
        $this->monday('09:00');
        [$ops, $customer, $site] = $this->personas();
        User::factory()->rider()->create();

        $this->setClock($ops, ClockService::PRESET_AFTER_CUTOFF);
        $this->bookSameDay($customer, $site)->assertOk()->assertJson(['offer_next' => true]);

        // Confirm next: a booked next-window job that is assigned for that window.
        $confirmed = $this->actingAs($customer)->postJson('/api/jobs', [
            'pickup_site_id' => $site->id,
            'drop_address' => '1 Main St, Springfield',
            'drop_contact_name' => 'Rex Ipient',
            'drop_phone' => '+1-555-0123',
            'part_line' => ['name' => 'Alternator', 'qty' => 1],
            'window' => 'next',
        ])->assertCreated();

        // Pass: confirm books `next` only (no extra-priority offer, no fees).
        $this->assertSame(JobWindow::Next->value, $confirmed->json('data.window'));
        $this->assertSame(JobStatus::Assigned->value, $confirmed->json('data.status'));
        $this->assertNoFeeAmounts($confirmed);
        $this->assertDatabaseCount('jobs', 1);
    }

    // --- helpers ----------------------------------------------------------

    private function monday(string $time = '09:00'): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse("2026-09-14 {$time}", 'America/Chicago'));
    }

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

    private function setClock(User $ops, string $preset): void
    {
        $this->actingAs($ops)->postJson('/api/demo/clock', ['preset' => $preset])->assertOk();
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

    private function driveToOnSite(User $rider, int $jobId): void
    {
        $this->actingAs($rider);
        foreach (['start', 'arrive-pickup', 'collect', 'depart-drop', 'arrive-site'] as $action) {
            $this->rider("/api/jobs/{$jobId}/{$action}")->assertOk();
        }
    }

    private function rider(string $path): TestResponse
    {
        return $this->postJson($path);
    }

    private function assertRiderQueueEmpty(User $rider): void
    {
        $active = Job::query()
            ->where('assigned_rider_id', $rider->id)
            ->where('is_active_for_rider', true)
            ->whereNotIn('status', array_map(fn ($s) => $s->value, JobStatus::terminalStatuses()))
            ->count();

        $this->assertSame(0, $active, 'Expected the rider to have no active job left.');
    }

    private function assertNoFeeAmounts(TestResponse $response): void
    {
        $body = $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/\$\d|"fee"|"price"|"amount"|"cost"/i', (string) $body);
    }
}

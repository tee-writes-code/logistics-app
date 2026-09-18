<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FailReason;
use App\Enums\JobStatus;
use App\Events\JobTransitioned;
use App\Models\Job;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RiderExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,ZmFrZS1zaWduYXR1cmU=';

    private const PHOTO = 'data:image/jpeg;base64,ZmFrZS1waG90bw==';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function rider(): User
    {
        return User::factory()->rider()->create();
    }

    private function assignedJob(User $rider, JobStatus $status, int $position = 1, bool $active = true): Job
    {
        return Job::factory()
            ->status($status)
            ->create([
                'assigned_rider_id' => $rider->id,
                'assigned_at' => now(),
                'queue_position' => $position,
                'is_active_for_rider' => $active,
            ]);
    }

    public function test_queue_returns_only_the_riders_own_live_jobs_ordered(): void
    {
        $rider = $this->rider();
        $this->assignedJob($rider, JobStatus::Assigned, position: 2, active: false);
        $active = $this->assignedJob($rider, JobStatus::EnRoutePickup, position: 1, active: true);
        // Another rider's job and a terminal job are excluded.
        $this->assignedJob($rider, JobStatus::Delivered, position: 3, active: false);
        Job::factory()->status(JobStatus::Assigned)->create(['assigned_rider_id' => $this->rider()->id]);

        $response = $this->actingAs($rider)->getJson('/api/rider/queue')->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame($active->id, $response->json('data.0.id'));
        $this->assertTrue($response->json('data.0.is_active_for_rider'));
    }

    public function test_empty_queue_returns_an_empty_list(): void
    {
        $this->actingAs($this->rider())
            ->getJson('/api/rider/queue')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_rider_cannot_act_on_another_riders_job(): void
    {
        $owner = $this->rider();
        $intruder = $this->rider();
        $job = $this->assignedJob($owner, JobStatus::Assigned);

        $this->actingAs($intruder)
            ->postJson("/api/jobs/{$job->id}/start")
            ->assertForbidden();
    }

    public function test_acting_on_a_non_active_owned_job_returns_409(): void
    {
        $rider = $this->rider();
        $this->assignedJob($rider, JobStatus::EnRoutePickup, position: 1, active: true);
        $queued = $this->assignedJob($rider, JobStatus::Assigned, position: 2, active: false);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$queued->id}/start")
            ->assertStatus(409);
    }

    public function test_full_happy_path_from_assigned_to_delivered(): void
    {
        Storage::fake('local');
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::Assigned);

        $steps = [
            ['start', JobStatus::EnRoutePickup],
            ['arrive-pickup', JobStatus::AtPickup],
            ['collect', JobStatus::PickedUp],
            ['depart-drop', JobStatus::EnRouteDrop],
            ['arrive-site', JobStatus::OnSite],
        ];

        foreach ($steps as [$action, $status]) {
            $this->actingAs($rider)
                ->postJson("/api/jobs/{$job->id}/{$action}")
                ->assertOk()
                ->assertJsonPath('data.status', $status->value);
        }

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/deliver", ['signature' => self::SIGNATURE])
            ->assertOk()
            ->assertJsonPath('data.status', JobStatus::Delivered->value);

        $job->refresh();
        $this->assertNotNull($job->picked_up_at);
        $this->assertNotNull($job->delivered_at);
        $this->assertFalse($job->is_active_for_rider);
        $this->assertDatabaseCount('pods', 1);

        // Every step wrote a timeline event.
        foreach (['en_route_pickup', 'at_pickup', 'picked_up', 'en_route_drop', 'on_site', 'delivered'] as $type) {
            $this->assertDatabaseHas('job_timeline_events', ['job_id' => $job->id, 'type' => $type]);
        }
    }

    public function test_illegal_transition_returns_409_with_allowed_states(): void
    {
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::Assigned);

        // collect (-> picked_up) is not legal from assigned. The allowed set lists
        // the map edges out of `assigned`: the rider's forward step plus the
        // agent-driven `cancelled`/`booked` replan edges (no rider endpoint can
        // request the latter two).
        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/collect")
            ->assertStatus(409)
            ->assertJsonPath('allowed', [
                JobStatus::EnRoutePickup->value,
                JobStatus::Cancelled->value,
                JobStatus::Booked->value,
            ]);
    }

    public function test_deliver_succeeds_with_signature_and_no_photo(): void
    {
        Storage::fake('local');
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::OnSite);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/deliver", ['signature' => self::SIGNATURE])
            ->assertOk();

        $pod = $job->pod()->firstOrFail();
        $this->assertNotNull($pod->signature_path);
        $this->assertNull($pod->photo_path);
        $this->assertSame($rider->id, $pod->delivered_by);
        Storage::disk('local')->assertExists($pod->signature_path);
    }

    public function test_deliver_stores_optional_photo_when_supplied(): void
    {
        Storage::fake('local');
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::OnSite);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/deliver", [
                'signature' => self::SIGNATURE,
                'photo' => self::PHOTO,
            ])
            ->assertOk();

        $pod = $job->pod()->firstOrFail();
        $this->assertNotNull($pod->photo_path);
        Storage::disk('local')->assertExists($pod->photo_path);
    }

    public function test_deliver_is_rejected_without_a_signature(): void
    {
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::OnSite);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/deliver", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('signature');

        $this->assertDatabaseCount('pods', 0);
        $this->assertSame(JobStatus::OnSite, $job->refresh()->status);
    }

    public function test_double_submit_deliver_is_idempotent(): void
    {
        Storage::fake('local');
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::OnSite);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/deliver", ['signature' => self::SIGNATURE])
            ->assertOk();

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/deliver", ['signature' => self::SIGNATURE])
            ->assertOk()
            ->assertJsonPath('data.status', JobStatus::Delivered->value);

        $this->assertDatabaseCount('pods', 1);
    }

    public function test_fail_stores_reason_and_blocks_a_later_deliver(): void
    {
        // Past today's cutoff, so the iter-4 Exception agent raises a confirm ask
        // instead of a same-day reattempt: the job stays `failed` and blocked.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 16:00', 'America/Chicago'));
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::OnSite);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/fail", ['reason' => FailReason::Closed->value, 'note' => 'Gate locked'])
            ->assertOk()
            ->assertJsonPath('data.status', JobStatus::Failed->value);

        $this->assertDatabaseHas('fail_records', [
            'job_id' => $job->id,
            'reason' => FailReason::Closed->value,
            'note' => 'Gate locked',
            'recorded_by' => $rider->id,
        ]);

        // Deliver on a failed job is blocked (awaiting new plan).
        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/deliver", ['signature' => self::SIGNATURE])
            ->assertStatus(409);

        $this->assertDatabaseCount('pods', 0);
    }

    public function test_fail_requires_a_valid_reason(): void
    {
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::OnSite);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/fail", ['reason' => 'nonsense'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_return_complete_succeeds_without_a_photo(): void
    {
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::Returning);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/return-complete")
            ->assertOk()
            ->assertJsonPath('data.status', JobStatus::Returned->value);

        $this->assertDatabaseCount('return_photos', 0);
    }

    public function test_return_complete_stores_an_optional_photo(): void
    {
        Storage::fake('local');
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::Returning);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/return-complete", ['return_photo' => self::PHOTO])
            ->assertOk()
            ->assertJsonPath('data.status', JobStatus::Returned->value);

        $this->assertDatabaseCount('return_photos', 1);
        $photo = $job->returnPhotos()->firstOrFail();
        Storage::disk('local')->assertExists($photo->photo_path);
        $this->assertSame($rider->id, $photo->recorded_by);
    }

    public function test_completing_a_job_promotes_the_next_queued_job(): void
    {
        Storage::fake('local');
        $rider = $this->rider();
        $first = $this->assignedJob($rider, JobStatus::OnSite, position: 1, active: true);
        $second = $this->assignedJob($rider, JobStatus::Assigned, position: 2, active: false);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$first->id}/deliver", ['signature' => self::SIGNATURE])
            ->assertOk();

        $this->assertFalse($first->refresh()->is_active_for_rider);
        $this->assertTrue($second->refresh()->is_active_for_rider);
    }

    public function test_transition_dispatches_the_domain_event(): void
    {
        Event::fake([JobTransitioned::class]);
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::Assigned);

        $this->actingAs($rider)->postJson("/api/jobs/{$job->id}/start")->assertOk();

        Event::assertDispatched(JobTransitioned::class, function (JobTransitioned $event) use ($job): bool {
            return $event->job->id === $job->id
                && $event->from === JobStatus::Assigned
                && $event->to === JobStatus::EnRoutePickup
                && $event->actorRole === 'rider';
        });
    }

    public function test_pod_image_is_served_to_the_assigned_rider_and_guarded_from_others(): void
    {
        Storage::fake('local');
        $rider = $this->rider();
        $job = $this->assignedJob($rider, JobStatus::OnSite);

        $this->actingAs($rider)
            ->postJson("/api/jobs/{$job->id}/deliver", ['signature' => self::SIGNATURE])
            ->assertOk();

        $this->actingAs($rider)
            ->get("/api/jobs/{$job->id}/pod-image?type=signature")
            ->assertOk();

        // A different customer cannot fetch the image.
        $this->actingAs(User::factory()->customer()->create())
            ->get("/api/jobs/{$job->id}/pod-image?type=signature")
            ->assertForbidden();
    }
}

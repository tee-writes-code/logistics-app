<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\JobWindow;
use App\Models\PickupSite;
use App\Models\SavedDrop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{0: User, 1: PickupSite}
     */
    private function customerWithSite(): array
    {
        $customer = User::factory()->customer()->create();
        $site = PickupSite::factory()->for($customer, 'customer')->create();

        return [$customer, $site];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(PickupSite $site, array $overrides = []): array
    {
        return array_merge([
            'pickup_site_id' => $site->id,
            'drop_address' => '500 Delivery Rd',
            'drop_contact_name' => 'Robin Recipient',
            'drop_phone' => '+1-555-9999',
            'part_line' => [
                'name' => 'Alternator',
                'sku' => 'ALT-1',
                'qty' => 2,
                'serial' => 'SN-1',
            ],
            'window' => 'same_day',
            'notes' => 'Leave at desk',
        ], $overrides);
    }

    public function test_booking_before_cutoff_with_hours_remaining_assigns_a_rider(): void
    {
        // iter-4 closes the booking->assign chain: Dispatch runs synchronously on
        // a fits-same-day booking, so the job arrives `assigned` (not `booked`)
        // with a rider, a deterministic ETA, and a queue position. The offer-next
        // gate is unchanged and tested separately below.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:00', 'America/Chicago'));
        $rider = User::factory()->rider()->create();
        [$customer, $site] = $this->customerWithSite();

        $response = $this->actingAs($customer)
            ->postJson('/api/jobs', $this->payload($site))
            ->assertCreated()
            ->assertJsonPath('data.status', JobStatus::Assigned->value)
            ->assertJsonPath('data.window', JobWindow::SameDay->value)
            ->assertJsonPath('data.assigned_rider_id', $rider->id)
            ->assertJsonPath('data.part_line.quantity', 2);

        $jobId = $response->json('data.id');
        $this->assertNotNull($response->json('data.eta_at'));
        $this->assertSame(0, $response->json('data.queue_position'));

        $this->assertDatabaseHas('jobs', [
            'id' => $jobId,
            'customer_id' => $customer->id,
            'status' => JobStatus::Assigned->value,
            'assigned_rider_id' => $rider->id,
            'is_active_for_rider' => true,
        ]);
        $this->assertDatabaseHas('job_timeline_events', [
            'job_id' => $jobId,
            'type' => 'booked',
        ]);
        // Dispatch logged the assignment on the agent workbench feed.
        $this->assertDatabaseHas('agent_action_logs', [
            'job_id' => $jobId,
            'agent' => 'dispatch',
        ]);
    }

    public function test_booking_with_no_assignable_rider_stays_booked(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:00', 'America/Chicago'));
        [$customer, $site] = $this->customerWithSite();

        $this->actingAs($customer)
            ->postJson('/api/jobs', $this->payload($site))
            ->assertCreated()
            ->assertJsonPath('data.status', JobStatus::Booked->value)
            ->assertJsonPath('data.assigned_rider_id', null);
    }

    public function test_same_day_that_cannot_finish_returns_offer_next_and_creates_no_job(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 16:00', 'America/Chicago'));
        [$customer, $site] = $this->customerWithSite();

        $this->actingAs($customer)
            ->postJson('/api/jobs', $this->payload($site))
            ->assertOk()
            ->assertJsonPath('offer_next', true);

        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_accepting_offer_next_creates_a_booked_next_job(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 16:00', 'America/Chicago'));
        [$customer, $site] = $this->customerWithSite();

        $this->actingAs($customer)
            ->postJson('/api/jobs', $this->payload($site, [
                'window' => 'next',
                'offer_next_accepted' => true,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.status', JobStatus::Booked->value)
            ->assertJsonPath('data.window', JobWindow::Next->value);

        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_saving_a_drop_during_booking_upserts_by_label(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:00', 'America/Chicago'));
        [$customer, $site] = $this->customerWithSite();
        SavedDrop::factory()->for($customer, 'customer')->create(['label' => 'Client A']);

        $this->actingAs($customer)
            ->postJson('/api/jobs', $this->payload($site, [
                'save_drop' => true,
                'save_drop_label' => 'Client A',
            ]))
            ->assertCreated();

        // Upsert by label: still exactly one drop with that label for this customer.
        $this->assertSame(1, $customer->savedDrops()->where('label', 'Client A')->count());
        $this->assertDatabaseHas('saved_drops', [
            'user_id' => $customer->id,
            'label' => 'Client A',
            'address' => '500 Delivery Rd',
        ]);
    }

    public function test_rider_cannot_book_a_job(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:00', 'America/Chicago'));
        $rider = User::factory()->rider()->create();
        $site = PickupSite::factory()->create();

        $this->actingAs($rider)
            ->postJson('/api/jobs', $this->payload($site))
            ->assertForbidden();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\PickupSite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickupSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_lists_only_their_own_pickup_sites(): void
    {
        $customer = User::factory()->customer()->create();
        $mine = PickupSite::factory()->for($customer, 'customer')->create();
        PickupSite::factory()->create(); // someone else's

        $this->actingAs($customer)
            ->getJson('/api/pickup-sites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_customer_can_create_a_pickup_site(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->postJson('/api/pickup-sites', [
                'label' => 'Main Depot',
                'address' => '1 Depot Way',
                'contact_name' => 'Dana',
                'contact_phone' => '+1-555-0000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Main Depot');

        $this->assertDatabaseHas('pickup_sites', [
            'user_id' => $customer->id,
            'label' => 'Main Depot',
        ]);
    }

    public function test_customer_cannot_update_another_customers_pickup_site(): void
    {
        $customer = User::factory()->customer()->create();
        $site = PickupSite::factory()->create();

        $this->actingAs($customer)
            ->putJson("/api/pickup-sites/{$site->id}", [
                'label' => 'Hijacked',
                'address' => 'X',
            ])
            ->assertForbidden();
    }

    public function test_pickup_site_delete_is_blocked_while_an_open_job_references_it(): void
    {
        $customer = User::factory()->customer()->create();
        $site = PickupSite::factory()->for($customer, 'customer')->create();
        Job::factory()->for($customer, 'customer')->for($site, 'pickupSite')
            ->status(JobStatus::Booked)->create();

        $this->actingAs($customer)
            ->deleteJson("/api/pickup-sites/{$site->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('pickup_sites', ['id' => $site->id]);
    }

    public function test_pickup_site_delete_succeeds_when_only_terminal_jobs_reference_it(): void
    {
        $customer = User::factory()->customer()->create();
        $site = PickupSite::factory()->for($customer, 'customer')->create();
        Job::factory()->for($customer, 'customer')->for($site, 'pickupSite')
            ->status(JobStatus::Delivered)->create();

        $this->actingAs($customer)
            ->deleteJson("/api/pickup-sites/{$site->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('pickup_sites', ['id' => $site->id]);
    }
}

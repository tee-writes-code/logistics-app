<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SavedDrop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedDropTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_lists_only_their_own_saved_drops(): void
    {
        $customer = User::factory()->customer()->create();
        $mine = SavedDrop::factory()->for($customer, 'customer')->create();
        SavedDrop::factory()->create();

        $this->actingAs($customer)
            ->getJson('/api/saved-drops')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_customer_can_create_a_saved_drop(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->postJson('/api/saved-drops', [
                'label' => 'Head Office',
                'address' => '9 Central Ave',
                'contact_name' => 'Riley',
                'phone' => '+1-555-1234',
            ])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Head Office');

        $this->assertDatabaseHas('saved_drops', [
            'user_id' => $customer->id,
            'label' => 'Head Office',
            'phone' => '+1-555-1234',
        ]);
    }

    public function test_customer_cannot_delete_another_customers_saved_drop(): void
    {
        $customer = User::factory()->customer()->create();
        $drop = SavedDrop::factory()->create();

        $this->actingAs($customer)
            ->deleteJson("/api/saved-drops/{$drop->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('saved_drops', ['id' => $drop->id]);
    }
}

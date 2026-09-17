<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Enums\JobWindow;
use App\Models\Job;
use App\Models\PickupSite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Job>
 */
class JobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => User::factory()->customer(),
            // Keep the pickup site owned by the same customer as the job.
            'pickup_site_id' => fn (array $attributes) => PickupSite::factory()
                ->create(['user_id' => $attributes['customer_id']])->id,
            'drop_address' => fake()->address(),
            'drop_contact_name' => fake()->name(),
            'drop_contact_phone' => fake()->phoneNumber(),
            'notes' => fake()->optional()->sentence(),
            'instructions' => fake()->optional()->sentence(),
            'window' => fake()->randomElement(JobWindow::cases()),
            'status' => JobStatus::Booked,
            'assigned_rider_id' => null,
            'queue_position' => null,
            'is_active_for_rider' => false,
            'eta_at' => null,
            'booked_at' => now(),
            'assigned_at' => null,
            'picked_up_at' => null,
            'delivered_at' => null,
            'cancelled_at' => null,
        ];
    }

    /**
     * Set the job to a specific lifecycle status.
     */
    public function status(JobStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    /**
     * Assign the job to a rider (activates it in the rider's queue).
     */
    public function assignedTo(User $rider): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_rider_id' => $rider->id,
            'assigned_at' => now(),
            'is_active_for_rider' => true,
            'queue_position' => 1,
        ]);
    }
}

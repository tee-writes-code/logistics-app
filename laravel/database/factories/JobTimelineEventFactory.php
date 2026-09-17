<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\JobTimelineEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobTimelineEvent>
 */
class JobTimelineEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'type' => fake()->randomElement(['status_changed', 'note_added', 'assigned', 'notified']),
            'actor_role' => fake()->randomElement(['ops', 'rider', 'customer', 'system']),
            'agent' => null,
            'description' => fake()->sentence(),
            'meta' => ['source' => 'seed'],
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\RecipientAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipientAction>
 */
class RecipientActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'type' => fake()->randomElement(['delivery_instruction', 'hold', 'receive_confirm', 'refuse']),
            'note' => fake()->optional()->sentence(),
            'meta' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\AgentType;
use App\Models\AgentActionLog;
use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentActionLog>
 */
class AgentActionLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'agent' => fake()->randomElement(AgentType::cases()),
            'goal' => fake()->sentence(),
            'last_action' => fake()->sentence(),
            'pending_ask' => fake()->optional()->sentence(),
            'meta' => ['source' => 'seed'],
        ];
    }
}

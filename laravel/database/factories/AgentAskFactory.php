<?php

namespace Database\Factories;

use App\Enums\AgentAskStatus;
use App\Enums\AgentAskType;
use App\Enums\AgentType;
use App\Models\AgentAsk;
use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentAsk>
 */
class AgentAskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'agent' => AgentType::Exception,
            'type' => AgentAskType::Next,
            'status' => AgentAskStatus::Pending,
            'payload' => ['origin' => 'fail'],
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }

    public function type(AgentAskType $type): static
    {
        return $this->state(fn (array $attributes) => ['type' => $type]);
    }

    public function status(AgentAskStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}

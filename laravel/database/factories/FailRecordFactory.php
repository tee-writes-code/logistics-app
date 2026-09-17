<?php

namespace Database\Factories;

use App\Enums\FailReason;
use App\Models\FailRecord;
use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FailRecord>
 */
class FailRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'reason' => fake()->randomElement(FailReason::cases()),
            'note' => fake()->optional()->sentence(),
        ];
    }
}

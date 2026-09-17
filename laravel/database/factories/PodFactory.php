<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\Pod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pod>
 */
class PodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'photo_path' => 'pods/'.fake()->uuid().'.jpg',
            'signature_path' => 'signatures/'.fake()->uuid().'.png',
        ];
    }
}

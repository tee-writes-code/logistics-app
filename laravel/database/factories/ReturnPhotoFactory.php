<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\ReturnPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnPhoto>
 */
class ReturnPhotoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'photo_path' => 'returns/'.fake()->uuid().'.jpg',
        ];
    }
}

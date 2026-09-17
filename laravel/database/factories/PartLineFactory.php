<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\PartLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PartLine>
 */
class PartLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'name' => fake()->words(2, true),
            'sku' => strtoupper(Str::random(8)),
            'quantity' => fake()->numberBetween(1, 5),
            'serial' => fake()->optional()->bothify('SN-####-????'),
        ];
    }
}

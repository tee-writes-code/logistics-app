<?php

namespace Database\Factories;

use App\Models\SavedDrop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedDrop>
 */
class SavedDropFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->customer(),
            'label' => fake()->word().' Site',
            'address' => fake()->address(),
            'contact_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
        ];
    }
}

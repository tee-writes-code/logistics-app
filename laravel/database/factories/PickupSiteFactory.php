<?php

namespace Database\Factories;

use App\Models\PickupSite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PickupSite>
 */
class PickupSiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->customer(),
            'label' => fake()->company().' Warehouse',
            'address' => fake()->address(),
            'contact_name' => fake()->name(),
            'contact_phone' => fake()->phoneNumber(),
        ];
    }
}

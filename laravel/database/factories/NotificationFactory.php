<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\Job;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'user_id' => null,
            'role' => fake()->randomElement(UserRole::cases()),
            'channel' => NotificationChannel::InApp,
            'message' => fake()->sentence(),
            'magic_link' => null,
            'read_at' => null,
        ];
    }
}

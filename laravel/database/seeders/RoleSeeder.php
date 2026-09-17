<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleSeeder extends Seeder
{
    /**
     * Seed the core accounts: 1 ops, 1 customer (with 2 pickup sites),
     * and 5 assignable riders. Idempotent via updateOrCreate on email.
     */
    public function run(): void
    {
        $password = Hash::make('password');

        User::updateOrCreate(
            ['email' => 'ops@logistics.test'],
            ['name' => 'Olivia Ops', 'password' => $password, 'role' => UserRole::Ops],
        );

        $customer = User::updateOrCreate(
            ['email' => 'customer@logistics.test'],
            ['name' => 'Casey Customer', 'password' => $password, 'role' => UserRole::Customer],
        );

        $sites = [
            ['label' => 'Downtown Depot', 'address' => '100 Market St, Springfield', 'contact_name' => 'Dana Dispatch', 'contact_phone' => '+1-555-0100'],
            ['label' => 'North Warehouse', 'address' => '850 Industrial Ave, Springfield', 'contact_name' => 'Nate North', 'contact_phone' => '+1-555-0200'],
        ];

        foreach ($sites as $site) {
            $customer->pickupSites()->updateOrCreate(
                ['label' => $site['label']],
                $site,
            );
        }

        for ($i = 1; $i <= 5; $i++) {
            User::updateOrCreate(
                ['email' => "rider{$i}@logistics.test"],
                ['name' => "Rider {$i}", 'password' => $password, 'role' => UserRole::Rider],
            );
        }
    }
}

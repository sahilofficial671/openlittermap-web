<?php

namespace Database\Seeders; // With laravel 8+, seeders are now namespaced

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call(PlanSeeder::class);
        $this->call(DonationAmountsSeeder::class);
    }
}

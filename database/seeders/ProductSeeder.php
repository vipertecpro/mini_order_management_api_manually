<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $seller = User::firstWhere('email', 'test@example.com') ?? User::factory()->create();

        Product::factory()
            ->count(30)
            ->for($seller, 'creator')
            ->create();
    }
}

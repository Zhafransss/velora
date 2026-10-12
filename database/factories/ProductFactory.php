<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'price' => fake()->numberBetween(10, 500) * 1000,
            'stock_status' => Product::STOCK_UNKNOWN,
            'is_active' => true,
        ];
    }
}

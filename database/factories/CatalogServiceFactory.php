<?php

namespace Database\Factories;

use App\Models\CatalogService;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogService> */
class CatalogServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'price_from' => 100000,
            'price_to' => 250000,
            'duration_minutes' => 60,
            'is_active' => true,
        ];
    }
}

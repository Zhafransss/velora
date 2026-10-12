<?php

namespace Database\Factories;

use App\Models\BusinessProfile;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessProfile> */
class BusinessProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'business_type' => fake()->randomElement(['kuliner', 'fashion', 'jasa', 'retail']),
            'description' => fake()->sentence(),
            'phone' => '08' . fake()->numerify('##########'),
            'address' => fake()->address(),
            'opening_hours' => ['mon-fri' => '09:00-17:00'],
        ];
    }
}

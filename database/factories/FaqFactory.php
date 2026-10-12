<?php

namespace Database\Factories;

use App\Models\Faq;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Faq> */
class FaqFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'question' => rtrim(fake()->sentence(), '.') . '?',
            'answer' => fake()->paragraph(),
            'is_active' => true,
        ];
    }
}

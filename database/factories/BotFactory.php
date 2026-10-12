<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bot> */
class BotFactory extends Factory
{
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory()] + Bot::defaults();
    }
}

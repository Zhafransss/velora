<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_code' => 'small',
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addDays(30),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function expiredPeriod(): static
    {
        return $this->state(fn () => ['current_period_start' => now()->subDays(40), 'current_period_end' => now()->subDay()]);
    }
}

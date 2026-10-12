<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\Channel;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Channel> */
class ChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // Pada pemakaian nyata, bot harus milik tenant yang sama; berikan bot_id eksplisit di tes.
            'bot_id' => fn (array $attrs) => Bot::factory()->create(['tenant_id' => $attrs['tenant_id']])->id,
            'type' => Channel::TYPE_WHATSAPP,
            'name' => 'WhatsApp Utama',
            'status' => Channel::STATUS_CONNECTED,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}

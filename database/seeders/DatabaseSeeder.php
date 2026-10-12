<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Akun dummy hanya untuk lingkungan non-produksi (password default factory: "password").
        if (app()->environment('production')) {
            return;
        }

        User::factory()->platformAdmin()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}

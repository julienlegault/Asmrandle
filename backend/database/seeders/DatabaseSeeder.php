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
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Seed codespace data only in development (codespace or local/testing environments)
        // Never seed in production
        if (app()->environment(['local', 'testing']) || env('CODESPACES') === 'true') {
            $this->call(CodespaceSeeder::class);
        }
    }
}

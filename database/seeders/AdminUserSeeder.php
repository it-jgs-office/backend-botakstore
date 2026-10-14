<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@botakstore.id'],
            [
                'name' => 'Admin BotakStore',
                'phone' => '081100000000',
                'password' => 'botakstore26!',
                'role' => 'admin',
                'email_verified_at' => now(),
            ],
        );
    }
}

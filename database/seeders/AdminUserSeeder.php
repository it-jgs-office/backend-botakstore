<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL');
        $password = (string) env('ADMIN_PASSWORD');
        if ($email === '' || $password === '') {
            throw new RuntimeException('ADMIN_EMAIL dan ADMIN_PASSWORD wajib diatur sebelum menjalankan seeder admin.');
        }

        User::query()->updateOrCreate(
            ['email' => strtolower($email)],
            [
                'name' => 'Admin BotakStore',
                'phone' => '081100000000',
                'password' => $password,
                'role' => 'admin',
                'email_verified_at' => now(),
            ],
        );
    }
}

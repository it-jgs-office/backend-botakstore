<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DemoCustomerSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'customer@botakstore.demo'],
            [
                'name' => 'Pelanggan Demo',
                'phone' => '081234567890',
                'password' => 'botak123',
                'role' => 'customer',
                'email_verified_at' => now(),
            ],
        );
    }
}

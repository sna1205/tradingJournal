<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LocalDemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('LOCAL_DEMO_EMAIL', 'demo@tradingjournal.local');
        $name = (string) env('LOCAL_DEMO_NAME', 'Local Demo Trader');
        $password = (string) env('LOCAL_DEMO_PASSWORD', 'password123');

        User::query()->updateOrCreate(
            ['email' => strtolower(trim($email))],
            [
                'name' => trim($name) !== '' ? $name : 'Local Demo Trader',
                'password' => Hash::make($password),
                'role' => 'trader',
                'email_verified_at' => now(),
            ]
        );
    }
}

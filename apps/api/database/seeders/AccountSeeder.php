<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('trade_images')->delete();
        DB::table('trades')->delete();
        Account::query()->delete();

        $demoEmail = strtolower(trim((string) env('LOCAL_DEMO_EMAIL', 'demo@tradingjournal.local')));
        $demoUser = User::query()->where('email', $demoEmail)->first();

        if ($demoUser === null) {
            $demoUser = User::factory()->create([
                'email' => $demoEmail,
            ]);
        }

        Account::factory()->count(3)->create([
            'user_id' => (int) $demoUser->id,
            'is_active' => true,
        ]);
    }
}

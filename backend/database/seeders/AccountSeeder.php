<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('trade_images')->delete();
        DB::table('trades')->delete();
        Account::query()->delete();

        Account::factory()->count(3)->create([
            'is_active' => true,
        ]);
    }
}

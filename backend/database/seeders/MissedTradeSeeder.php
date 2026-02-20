<?php

namespace Database\Seeders;

use App\Models\MissedTrade;
use Illuminate\Database\Seeder;

class MissedTradeSeeder extends Seeder
{
    public function run(): void
    {
        MissedTrade::query()->delete();

        MissedTrade::factory()->count(48)->create();
    }
}

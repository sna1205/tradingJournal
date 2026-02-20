<?php

namespace Database\Seeders;

use App\Models\Trade;
use Illuminate\Database\Seeder;

class TradeSeeder extends Seeder
{
    public function run(): void
    {
        Trade::query()->delete();

        Trade::factory()->count(180)->create();
    }
}

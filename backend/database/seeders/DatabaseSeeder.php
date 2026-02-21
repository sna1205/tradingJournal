<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AccountSeeder::class,
            TradeSeeder::class,
            TradeImageSeeder::class,
            MissedTradeSeeder::class,
            MissedTradeImageSeeder::class,
        ]);
    }
}

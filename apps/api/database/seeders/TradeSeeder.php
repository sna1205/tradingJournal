<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Instrument;
use App\Models\Trade;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TradeSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('trade_images')->delete();
        DB::table('trades')->delete();

        $accountIds = Account::query()->pluck('id')->all();
        if (count($accountIds) === 0) {
            $accountIds = Account::factory()->count(1)->create()->pluck('id')->all();
        }

        Trade::factory()
            ->count(180)
            ->state(fn () => ['account_id' => fake()->randomElement($accountIds)])
            ->create();

        $instrumentIdsBySymbol = Instrument::query()
            ->pluck('id', 'symbol')
            ->all();

        if (count($instrumentIdsBySymbol) > 0) {
            foreach ($instrumentIdsBySymbol as $symbol => $instrumentId) {
                Trade::query()
                    ->where('pair', $symbol)
                    ->update(['instrument_id' => (int) $instrumentId]);
            }
        }

        // Keep account balances consistent with seeded trade P&L.
        foreach ($accountIds as $accountId) {
            $starting = (float) (Account::query()->whereKey($accountId)->value('starting_balance') ?? 0);
            $pnl = (float) (Trade::query()->where('account_id', $accountId)->sum('profit_loss') ?? 0);

            DB::table('accounts')
                ->where('id', $accountId)
                ->update(['current_balance' => round($starting + $pnl, 2)]);
        }
    }
}

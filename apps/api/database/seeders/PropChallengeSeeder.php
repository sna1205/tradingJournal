<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\PropChallenge;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PropChallengeSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('prop_challenges')->delete();

        $accounts = Account::query()
            ->where('account_type', 'funded')
            ->orWhere('account_type', 'demo')
            ->orderBy('id')
            ->get();

        foreach ($accounts as $account) {
            PropChallenge::query()->create([
                'account_id' => (int) $account->id,
                'provider' => (string) $account->broker,
                'phase' => 'Phase 1',
                'starting_balance' => (float) $account->starting_balance,
                'profit_target_pct' => 10.0,
                'max_daily_loss_pct' => 5.0,
                'max_total_drawdown_pct' => 10.0,
                'min_trading_days' => 4,
                'start_date' => now()->subDays(30)->toDateString(),
                'status' => 'active',
                'passed_at' => null,
                'failed_at' => null,
            ]);
        }
    }
}


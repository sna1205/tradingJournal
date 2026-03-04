<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountRiskPolicySeeder extends Seeder
{
    public function run(): void
    {
        $rows = Account::query()
            ->pluck('id')
            ->map(fn (int $accountId): array => [
                'account_id' => $accountId,
                'max_risk_per_trade_pct' => 1.0000,
                'max_daily_loss_pct' => 5.0000,
                'max_total_drawdown_pct' => 10.0000,
                'max_open_risk_pct' => 2.0000,
                'enforce_hard_limits' => true,
                'allow_override' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        foreach ($rows as $row) {
            DB::table('account_risk_policies')->updateOrInsert(
                ['account_id' => $row['account_id']],
                $row
            );
        }
    }
}


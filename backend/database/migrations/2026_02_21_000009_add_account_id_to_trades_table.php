<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaultStartingBalance = max((float) env('ANALYTICS_STARTING_BALANCE', 10_000), 0.0);

        $defaultAccountId = (int) (DB::table('accounts')->orderBy('id')->value('id') ?? 0);
        if ($defaultAccountId <= 0) {
            $defaultAccountId = DB::table('accounts')->insertGetId([
                'user_id' => null,
                'name' => 'Primary Account',
                'broker' => 'N/A',
                'account_type' => 'personal',
                'starting_balance' => round($defaultStartingBalance, 2),
                'current_balance' => round($defaultStartingBalance, 2),
                'currency' => 'USD',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('trades', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('id');
        });

        DB::table('trades')
            ->whereNull('account_id')
            ->update(['account_id' => $defaultAccountId]);

        $pnl = (float) (DB::table('trades')
            ->where('account_id', $defaultAccountId)
            ->sum('profit_loss') ?? 0.0);
        DB::table('accounts')
            ->where('id', $defaultAccountId)
            ->update([
                'current_balance' => round($defaultStartingBalance + $pnl, 2),
                'updated_at' => now(),
            ]);

        Schema::table('trades', function (Blueprint $table) {
            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->restrictOnDelete();

            $table->index(['account_id', 'date'], 'trades_account_close_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropIndex('trades_account_close_date_index');
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};

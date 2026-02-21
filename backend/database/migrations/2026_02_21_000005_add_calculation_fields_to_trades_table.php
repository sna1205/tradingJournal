<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->decimal('actual_exit_price', 16, 6)->nullable()->after('take_profit');
            $table->decimal('risk_per_unit', 16, 6)->nullable()->after('actual_exit_price');
            $table->decimal('reward_per_unit', 16, 6)->nullable()->after('risk_per_unit');
            $table->decimal('monetary_risk', 14, 2)->nullable()->after('reward_per_unit');
            $table->decimal('monetary_reward', 14, 2)->nullable()->after('monetary_risk');
            $table->decimal('r_multiple', 14, 4)->nullable()->after('rr');
            $table->decimal('risk_percent', 8, 4)->nullable()->after('r_multiple');
            $table->decimal('account_balance_before_trade', 14, 2)->nullable()->after('risk_percent');
            $table->decimal('account_balance_after_trade', 14, 2)->nullable()->after('account_balance_before_trade');
            $table->boolean('followed_rules')->default(false)->after('account_balance_after_trade');
            $table->enum('emotion', [
                'neutral',
                'calm',
                'confident',
                'fearful',
                'greedy',
                'hesitant',
                'revenge',
            ])->default('neutral')->after('followed_rules');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn([
                'actual_exit_price',
                'risk_per_unit',
                'reward_per_unit',
                'monetary_risk',
                'monetary_reward',
                'r_multiple',
                'risk_percent',
                'account_balance_before_trade',
                'account_balance_after_trade',
                'followed_rules',
                'emotion',
            ]);
        });
    }
};


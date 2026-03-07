<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->foreignId('instrument_id')
                ->nullable()
                ->after('account_id')
                ->constrained('instruments')
                ->nullOnDelete();

            $table->decimal('gross_profit_loss', 18, 6)->nullable()->after('monetary_reward');
            $table->decimal('costs_total', 18, 6)->default(0)->after('gross_profit_loss');
            $table->decimal('commission', 18, 6)->default(0)->after('costs_total');
            $table->decimal('swap', 18, 6)->default(0)->after('commission');
            $table->decimal('spread_cost', 18, 6)->default(0)->after('swap');
            $table->decimal('slippage_cost', 18, 6)->default(0)->after('spread_cost');
            $table->text('risk_override_reason')->nullable()->after('emotion');

            $table->index(['instrument_id', 'date'], 'trades_instrument_close_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropIndex('trades_instrument_close_date_index');
            $table->dropForeign(['instrument_id']);
            $table->dropColumn([
                'instrument_id',
                'gross_profit_loss',
                'costs_total',
                'commission',
                'swap',
                'spread_cost',
                'slippage_cost',
                'risk_override_reason',
            ]);
        });
    }
};


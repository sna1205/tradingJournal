<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['date', 'pair'], 'trades_close_date_symbol_index');
            $table->index(['session', 'date'], 'trades_session_close_date_index');
            $table->index(['model', 'date'], 'trades_strategy_model_close_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropIndex('trades_close_date_symbol_index');
            $table->dropIndex('trades_session_close_date_index');
            $table->dropIndex('trades_strategy_model_close_date_index');
            $table->dropSoftDeletes();
        });
    }
};


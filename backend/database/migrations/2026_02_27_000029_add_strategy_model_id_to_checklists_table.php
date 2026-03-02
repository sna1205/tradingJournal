<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklists', function (Blueprint $table): void {
            $table->foreignId('strategy_model_id')
                ->nullable()
                ->after('account_id')
                ->constrained('strategy_models')
                ->nullOnDelete();

            $table->index(['strategy_model_id', 'is_active'], 'checklists_strategy_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('checklists', function (Blueprint $table): void {
            $table->dropIndex('checklists_strategy_active_index');
            $table->dropForeign(['strategy_model_id']);
            $table->dropColumn('strategy_model_id');
        });
    }
};

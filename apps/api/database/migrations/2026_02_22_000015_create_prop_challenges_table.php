<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prop_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->unique()
                ->constrained('accounts')
                ->cascadeOnDelete();
            $table->string('provider', 120)->default('FTMO');
            $table->string('phase', 80)->default('Phase 1');
            $table->decimal('starting_balance', 18, 2);
            $table->decimal('profit_target_pct', 8, 4)->default(10.0000);
            $table->decimal('max_daily_loss_pct', 8, 4)->default(5.0000);
            $table->decimal('max_total_drawdown_pct', 8, 4)->default(10.0000);
            $table->unsignedInteger('min_trading_days')->default(4);
            $table->date('start_date');
            $table->enum('status', ['active', 'passed', 'failed', 'paused'])->default('active');
            $table->timestamp('passed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prop_challenges');
    }
};


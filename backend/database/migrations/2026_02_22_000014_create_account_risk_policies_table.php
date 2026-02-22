<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_risk_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->unique()
                ->constrained('accounts')
                ->cascadeOnDelete();
            $table->decimal('max_risk_per_trade_pct', 8, 4)->default(1.0000);
            $table->decimal('max_daily_loss_pct', 8, 4)->default(5.0000);
            $table->decimal('max_total_drawdown_pct', 8, 4)->default(10.0000);
            $table->decimal('max_open_risk_pct', 8, 4)->default(2.0000);
            $table->boolean('enforce_hard_limits')->default(true);
            $table->boolean('allow_override')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_risk_policies');
    }
};


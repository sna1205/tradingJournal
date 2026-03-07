<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instruments', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 30)->unique();
            $table->string('asset_class', 40);
            $table->string('base_currency', 12);
            $table->string('quote_currency', 12);
            $table->decimal('contract_size', 20, 8);
            $table->decimal('tick_size', 20, 10);
            $table->decimal('tick_value', 20, 8);
            $table->decimal('pip_size', 20, 10);
            $table->decimal('min_lot', 12, 4)->default(0.0100);
            $table->decimal('lot_step', 12, 4)->default(0.0100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'symbol'], 'instruments_active_symbol_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instruments');
    }
};


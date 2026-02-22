<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')
                ->constrained('trades')
                ->cascadeOnDelete();
            $table->enum('leg_type', ['entry', 'exit']);
            $table->decimal('price', 16, 6);
            $table->decimal('quantity_lots', 12, 4);
            $table->dateTime('executed_at');
            $table->decimal('fees', 18, 6)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['trade_id', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_legs');
    }
};

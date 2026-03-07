<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->string('pair', 30)->index();
            $table->enum('direction', ['buy', 'sell'])->index();
            $table->decimal('entry_price', 16, 6);
            $table->decimal('stop_loss', 16, 6);
            $table->decimal('take_profit', 16, 6);
            $table->decimal('lot_size', 12, 4);
            $table->decimal('profit_loss', 14, 2);
            $table->decimal('rr', 8, 2);
            $table->string('session', 60)->index();
            $table->string('model', 120)->index();
            $table->dateTime('date')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};

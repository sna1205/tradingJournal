<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missed_trades', function (Blueprint $table) {
            $table->id();
            $table->string('pair', 30)->index();
            $table->string('model', 120)->index();
            $table->string('reason', 255);
            $table->dateTime('date')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missed_trades');
    }
};

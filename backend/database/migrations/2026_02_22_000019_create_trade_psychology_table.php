<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_psychology', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')
                ->unique()
                ->constrained('trades')
                ->cascadeOnDelete();
            $table->string('pre_emotion', 40)->nullable();
            $table->string('post_emotion', 40)->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->unsignedTinyInteger('stress_score')->nullable();
            $table->decimal('sleep_hours', 4, 2)->nullable();
            $table->boolean('impulse_flag')->default(false);
            $table->boolean('fomo_flag')->default(false);
            $table->boolean('revenge_flag')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['confidence_score', 'stress_score']);
            $table->index(['impulse_flag', 'fomo_flag', 'revenge_flag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_psychology');
    }
};

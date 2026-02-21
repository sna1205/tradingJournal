<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missed_trade_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('missed_trade_id')
                ->constrained('missed_trades')
                ->cascadeOnDelete();
            $table->string('image_url');
            $table->string('thumbnail_url');
            $table->unsignedBigInteger('file_size');
            $table->string('file_type', 40);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missed_trade_images');
    }
};


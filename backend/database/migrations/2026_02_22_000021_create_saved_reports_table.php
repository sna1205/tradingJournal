<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('scope', 20)->default('trades');
            $table->json('filters_json');
            $table->json('columns_json')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['scope', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};

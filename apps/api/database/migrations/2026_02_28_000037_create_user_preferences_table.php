<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('theme_mode', ['light', 'dark', 'forest', 'dawn'])->default('light');
            $table->string('profile_timezone', 64)->default('UTC');
            $table->string('profile_locale', 16)->default('en-US');
            $table->timestamps();

            $table->unique('user_id', 'user_preferences_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};

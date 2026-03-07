<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preference_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('changed_keys');
            $table->json('before_values');
            $table->json('after_values');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'user_preference_audits_user_created_idx');
            $table->index(['changed_by_user_id', 'created_at'], 'user_preference_audits_actor_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preference_audits');
    }
};

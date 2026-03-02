<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_enforcement_mode_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checklist_id')->nullable()->constrained('checklists')->nullOnDelete();
            $table->foreignId('checklist_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('from_enforcement_mode', ['soft', 'strict']);
            $table->enum('to_enforcement_mode', ['soft', 'strict']);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['checklist_id', 'created_at'], 'checklist_mode_audits_checklist_created_idx');
            $table->index(['changed_by_user_id', 'created_at'], 'checklist_mode_audits_actor_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_enforcement_mode_audits');
    }
};

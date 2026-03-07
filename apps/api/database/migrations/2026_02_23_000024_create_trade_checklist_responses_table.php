<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_checklist_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();
            $table->foreignId('checklist_id')->constrained('checklists')->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained('checklist_items')->cascadeOnDelete();
            $table->json('value')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['trade_id', 'checklist_item_id'], 'trade_checklist_unique_trade_item');
            $table->index(['trade_id', 'checklist_item_id'], 'trade_checklist_trade_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_checklist_responses');
    }
};

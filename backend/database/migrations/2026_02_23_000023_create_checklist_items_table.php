<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained('checklists')->cascadeOnDelete();
            $table->unsignedInteger('order_index')->default(0);
            $table->string('title', 220);
            $table->enum('type', ['checkbox', 'dropdown', 'number', 'text', 'scale']);
            $table->boolean('required')->default(false);
            $table->string('category', 80)->default('General');
            $table->text('help_text')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['checklist_id', 'order_index', 'is_active'], 'checklist_items_order_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_rule_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')
                ->constrained('trades')
                ->cascadeOnDelete();
            $table->foreignId('checklist_id')
                ->nullable()
                ->constrained('checklists')
                ->nullOnDelete();
            $table->unsignedInteger('checklist_revision')->nullable();
            $table->json('evaluated_inputs_json');
            $table->json('failed_rules_json')->nullable();
            $table->enum('decision', ['pass', 'fail']);
            $table->dateTime('evaluated_at');
            $table->string('engine_version', 80);
            $table->timestamps();

            $table->index(['trade_id', 'evaluated_at']);
            $table->index(['decision', 'evaluated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_rule_executions');
    }
};

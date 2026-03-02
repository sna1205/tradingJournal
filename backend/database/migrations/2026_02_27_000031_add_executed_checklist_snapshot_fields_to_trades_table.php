<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->foreignId('executed_checklist_id')
                ->nullable()
                ->after('checklist_incomplete')
                ->constrained('checklists')
                ->nullOnDelete();
            $table->unsignedInteger('executed_checklist_version')
                ->nullable()
                ->after('executed_checklist_id');
            $table->enum('executed_enforcement_mode', ['strict', 'soft', 'off'])
                ->nullable()
                ->after('executed_checklist_version');
            $table->json('failed_rule_ids')
                ->nullable()
                ->after('executed_enforcement_mode');
            $table->json('failed_rule_titles')
                ->nullable()
                ->after('failed_rule_ids');
            $table->timestamp('check_evaluated_at')
                ->nullable()
                ->after('failed_rule_titles');

            $table->index(['executed_checklist_id', 'check_evaluated_at'], 'trades_executed_checklist_eval_idx');
            $table->index(['executed_enforcement_mode', 'date'], 'trades_executed_enforcement_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->dropIndex('trades_executed_checklist_eval_idx');
            $table->dropIndex('trades_executed_enforcement_date_idx');
            $table->dropForeign(['executed_checklist_id']);
            $table->dropColumn([
                'executed_checklist_id',
                'executed_checklist_version',
                'executed_enforcement_mode',
                'failed_rule_ids',
                'failed_rule_titles',
                'check_evaluated_at',
            ]);
        });
    }
};

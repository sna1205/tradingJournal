<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            if (!Schema::hasColumn('trades', 'checklist_incomplete')) {
                $table->boolean('checklist_incomplete')->default(false)->after('followed_rules');
                $table->index(['checklist_incomplete', 'date'], 'trades_checklist_incomplete_date_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            if (Schema::hasColumn('trades', 'checklist_incomplete')) {
                $table->dropIndex('trades_checklist_incomplete_date_index');
                $table->dropColumn('checklist_incomplete');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_reports', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['user_id', 'scope'], 'saved_reports_user_scope_index');
        });

        Schema::table('missed_trades', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['user_id', 'date'], 'missed_trades_user_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('saved_reports', function (Blueprint $table) {
            $table->dropIndex('saved_reports_user_scope_index');
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('missed_trades', function (Blueprint $table) {
            $table->dropIndex('missed_trades_user_date_index');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

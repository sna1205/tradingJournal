<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->decimal('avg_entry_price', 16, 6)->nullable()->after('entry_price');
            $table->decimal('avg_exit_price', 16, 6)->nullable()->after('actual_exit_price');
            $table->decimal('realized_r_multiple', 14, 4)->nullable()->after('r_multiple');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn([
                'avg_entry_price',
                'avg_exit_price',
                'realized_r_multiple',
            ]);
        });
    }
};

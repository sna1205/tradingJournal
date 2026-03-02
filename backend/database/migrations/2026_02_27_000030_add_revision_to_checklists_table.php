<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklists', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1)->after('name');
        });

        DB::table('checklists')
            ->whereNull('revision')
            ->update(['revision' => 1]);
    }

    public function down(): void
    {
        Schema::table('checklists', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};

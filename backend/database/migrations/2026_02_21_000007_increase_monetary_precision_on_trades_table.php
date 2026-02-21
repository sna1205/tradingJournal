<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE trades MODIFY monetary_risk DECIMAL(18,6) NULL');
            DB::statement('ALTER TABLE trades MODIFY monetary_reward DECIMAL(18,6) NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE trades MODIFY monetary_risk DECIMAL(14,2) NULL');
            DB::statement('ALTER TABLE trades MODIFY monetary_reward DECIMAL(14,2) NULL');
        }
    }
};

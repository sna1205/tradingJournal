<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->decimal('fx_rate_quote_to_usd', 20, 10)->nullable()->after('slippage_cost');
            $table->string('fx_symbol_used', 30)->nullable()->after('fx_rate_quote_to_usd');
            $table->timestamp('fx_rate_timestamp')->nullable()->after('fx_symbol_used');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn([
                'fx_rate_quote_to_usd',
                'fx_symbol_used',
                'fx_rate_timestamp',
            ]);
        });
    }
};

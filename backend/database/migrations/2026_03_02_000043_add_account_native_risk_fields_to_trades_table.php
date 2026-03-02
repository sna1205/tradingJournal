<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->decimal('risk_amount_account_currency', 18, 6)
                ->nullable()
                ->after('monetary_risk');
            $table->string('risk_currency', 12)
                ->nullable()
                ->after('risk_amount_account_currency');
            $table->decimal('fx_rate_used', 20, 10)
                ->nullable()
                ->after('fx_rate_timestamp');
            $table->string('fx_pair_used', 64)
                ->nullable()
                ->after('fx_rate_used');
            $table->timestamp('fx_rate_provenance_at')
                ->nullable()
                ->after('fx_pair_used');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->dropColumn([
                'risk_amount_account_currency',
                'risk_currency',
                'fx_rate_used',
                'fx_pair_used',
                'fx_rate_provenance_at',
            ]);
        });
    }
};

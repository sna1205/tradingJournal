<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fx_rates', function (Blueprint $table): void {
            $table->string('provider', 80)->nullable()->after('rate');
            $table->string('source', 160)->nullable()->after('provider');
            $table->decimal('bid', 20, 10)->nullable()->after('source');
            $table->decimal('ask', 20, 10)->nullable()->after('bid');
            $table->decimal('mid', 20, 10)->nullable()->after('ask');
            $table->string('bid_provenance', 160)->nullable()->after('mid');
            $table->string('ask_provenance', 160)->nullable()->after('bid_provenance');
            $table->string('mid_provenance', 160)->nullable()->after('ask_provenance');
            $table->index('rate_updated_at', 'fx_rates_rate_updated_at_index');
        });

        Schema::table('fx_rate_snapshots', function (Blueprint $table): void {
            $table->timestamp('rate_updated_at')->nullable()->after('rate');
            $table->string('provider', 80)->nullable()->after('rate_updated_at');
            $table->string('source', 160)->nullable()->after('provider');
            $table->decimal('bid', 20, 10)->nullable()->after('source');
            $table->decimal('ask', 20, 10)->nullable()->after('bid');
            $table->decimal('mid', 20, 10)->nullable()->after('ask');
            $table->string('bid_provenance', 160)->nullable()->after('mid');
            $table->string('ask_provenance', 160)->nullable()->after('bid_provenance');
            $table->string('mid_provenance', 160)->nullable()->after('ask_provenance');
            $table->index('rate_updated_at', 'fx_rate_snapshots_rate_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('fx_rate_snapshots', function (Blueprint $table): void {
            $table->dropIndex('fx_rate_snapshots_rate_updated_at_index');
            $table->dropColumn([
                'rate_updated_at',
                'provider',
                'source',
                'bid',
                'ask',
                'mid',
                'bid_provenance',
                'ask_provenance',
                'mid_provenance',
            ]);
        });

        Schema::table('fx_rates', function (Blueprint $table): void {
            $table->dropIndex('fx_rates_rate_updated_at_index');
            $table->dropColumn([
                'provider',
                'source',
                'bid',
                'ask',
                'mid',
                'bid_provenance',
                'ask_provenance',
                'mid_provenance',
            ]);
        });
    }
};

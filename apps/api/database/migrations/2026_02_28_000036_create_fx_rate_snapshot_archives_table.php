<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rate_snapshot_archives', function (Blueprint $table): void {
            $table->id();
            $table->string('from_currency', 12);
            $table->string('to_currency', 12);
            $table->date('snapshot_date');
            $table->decimal('rate', 20, 10);
            $table->timestamp('rate_updated_at')->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('source', 160)->nullable();
            $table->decimal('bid', 20, 10)->nullable();
            $table->decimal('ask', 20, 10)->nullable();
            $table->decimal('mid', 20, 10)->nullable();
            $table->string('bid_provenance', 160)->nullable();
            $table->string('ask_provenance', 160)->nullable();
            $table->string('mid_provenance', 160)->nullable();
            $table->timestamp('archived_at')->useCurrent();
            $table->timestamps();

            $table->unique(['from_currency', 'to_currency', 'snapshot_date'], 'fx_snapshot_archives_pair_date_unique');
            $table->index(['snapshot_date', 'from_currency', 'to_currency'], 'fx_snapshot_archives_lookup_index');
            $table->index('rate_updated_at', 'fx_snapshot_archives_rate_updated_at_index');
            $table->index('archived_at', 'fx_snapshot_archives_archived_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rate_snapshot_archives');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rate_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 12);
            $table->string('to_currency', 12);
            $table->date('snapshot_date');
            $table->decimal('rate', 20, 10);
            $table->timestamps();

            $table->unique(['from_currency', 'to_currency', 'snapshot_date'], 'fx_rate_snapshots_pair_date_unique');
            $table->index(['snapshot_date', 'from_currency', 'to_currency'], 'fx_rate_snapshots_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rate_snapshots');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 12);
            $table->string('to_currency', 12);
            $table->decimal('rate', 20, 10);
            $table->timestamp('rate_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['from_currency', 'to_currency'], 'fx_rates_pair_unique');
            $table->index(['to_currency', 'from_currency'], 'fx_rates_to_from_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};

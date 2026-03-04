<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
            $table->index('expires_at', 'idempotency_keys_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropIndex('idempotency_keys_expires_at_index');
            $table->dropColumn('expires_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('name', 160);
            $table->enum('scope', ['global', 'account', 'strategy'])->default('global');
            $table->enum('enforcement_mode', ['soft', 'strict'])->default('soft');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'scope', 'is_active'], 'checklists_user_scope_active_index');
            $table->index(['account_id', 'is_active'], 'checklists_account_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklists');
    }
};

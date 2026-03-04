<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('broker', 120)->default('N/A');
            $table->enum('account_type', ['funded', 'personal', 'demo'])->default('personal');
            $table->decimal('starting_balance', 18, 2);
            $table->decimal('current_balance', 18, 2);
            $table->string('currency', 12)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'created_at'], 'accounts_active_created_at_index');
            $table->unique(['user_id', 'name'], 'accounts_user_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};


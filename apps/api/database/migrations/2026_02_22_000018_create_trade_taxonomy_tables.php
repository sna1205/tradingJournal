<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_models', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('setups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('killzones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 140)->unique();
            $table->string('session_enum', 20);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['session_enum', 'is_active']);
        });

        Schema::create('trade_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 140)->unique();
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('trade_tag_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();
            $table->foreignId('trade_tag_id')->constrained('trade_tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['trade_id', 'trade_tag_id'], 'trade_tag_map_unique');
            $table->index(['trade_tag_id', 'trade_id']);
        });

        Schema::table('trades', function (Blueprint $table) {
            $table->foreignId('strategy_model_id')
                ->nullable()
                ->after('instrument_id')
                ->constrained('strategy_models')
                ->nullOnDelete();
            $table->foreignId('setup_id')
                ->nullable()
                ->after('strategy_model_id')
                ->constrained('setups')
                ->nullOnDelete();
            $table->foreignId('killzone_id')
                ->nullable()
                ->after('setup_id')
                ->constrained('killzones')
                ->nullOnDelete();
            $table->string('session_enum', 20)->nullable()->after('session');

            $table->index(['strategy_model_id', 'date'], 'trades_strategy_model_date_idx');
            $table->index(['setup_id', 'date'], 'trades_setup_date_idx');
            $table->index(['killzone_id', 'date'], 'trades_killzone_date_idx');
            $table->index(['session_enum', 'date'], 'trades_session_enum_date_idx');
        });

        $now = now();
        DB::table('strategy_models')->insert([
            ['name' => 'General', 'slug' => 'general', 'description' => 'Default strategy model bucket.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Turtle Soup', 'slug' => 'turtle-soup', 'description' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'FVG', 'slug' => 'fvg', 'description' => 'Fair Value Gap model.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'CRT', 'slug' => 'crt', 'description' => 'Candle Range Theory model.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('setups')->insert([
            ['name' => 'Breakout', 'slug' => 'breakout', 'description' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Pullback', 'slug' => 'pullback', 'description' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Liquidity Sweep', 'slug' => 'liquidity-sweep', 'description' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Reversal', 'slug' => 'reversal', 'description' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('killzones')->insert([
            ['name' => 'Asia Open', 'slug' => 'asia-open', 'session_enum' => 'asia', 'description' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'London Open', 'slug' => 'london-open', 'session_enum' => 'london', 'description' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'New York Open', 'slug' => 'new-york-open', 'session_enum' => 'new_york', 'description' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'London/NY Overlap', 'slug' => 'london-ny-overlap', 'session_enum' => 'overlap', 'description' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropIndex('trades_strategy_model_date_idx');
            $table->dropIndex('trades_setup_date_idx');
            $table->dropIndex('trades_killzone_date_idx');
            $table->dropIndex('trades_session_enum_date_idx');
            $table->dropForeign(['strategy_model_id']);
            $table->dropForeign(['setup_id']);
            $table->dropForeign(['killzone_id']);
            $table->dropColumn([
                'strategy_model_id',
                'setup_id',
                'killzone_id',
                'session_enum',
            ]);
        });

        Schema::dropIfExists('trade_tag_map');
        Schema::dropIfExists('trade_tags');
        Schema::dropIfExists('killzones');
        Schema::dropIfExists('setups');
        Schema::dropIfExists('strategy_models');
    }
};

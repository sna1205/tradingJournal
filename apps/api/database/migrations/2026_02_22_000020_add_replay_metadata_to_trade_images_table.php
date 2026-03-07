<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_images', function (Blueprint $table) {
            $table->string('context_tag', 20)->nullable()->after('sort_order');
            $table->string('timeframe', 20)->nullable()->after('context_tag');
            $table->text('annotation_notes')->nullable()->after('timeframe');

            $table->index(['context_tag', 'timeframe'], 'trade_images_context_tf_idx');
        });
    }

    public function down(): void
    {
        Schema::table('trade_images', function (Blueprint $table) {
            $table->dropIndex('trade_images_context_tf_idx');
            $table->dropColumn([
                'context_tag',
                'timeframe',
                'annotation_notes',
            ]);
        });
    }
};

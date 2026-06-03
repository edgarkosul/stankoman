<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_products', function (Blueprint $table): void {
            $table->string('match_source')->nullable()->after('match_strategy');
            $table->boolean('match_locked')->default(false)->after('match_source');
            $table->timestamp('matched_at')->nullable()->after('match_locked');
            $table->foreignId('matched_by_user_id')
                ->nullable()
                ->after('matched_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['match_locked', 'matched_product_id'], 'legacy_products_match_locked_product_idx');
            $table->index(['match_source', 'match_strategy'], 'legacy_products_match_source_strategy_idx');
        });
    }

    public function down(): void
    {
        Schema::table('legacy_products', function (Blueprint $table): void {
            $table->dropForeign(['matched_by_user_id']);
            $table->dropIndex('legacy_products_match_locked_product_idx');
            $table->dropIndex('legacy_products_match_source_strategy_idx');
            $table->dropColumn([
                'match_source',
                'match_locked',
                'matched_at',
                'matched_by_user_id',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $hasInstructions = Schema::hasColumn('products', 'instructions');
        $hasVideo = Schema::hasColumn('products', 'video');

        if (! $hasInstructions || ! $hasVideo) {
            Schema::table('products', function (Blueprint $table) use ($hasInstructions, $hasVideo): void {
                if (! $hasInstructions) {
                    $table->longText('instructions')
                        ->nullable()
                        ->after('extra_description');
                }

                if (! $hasVideo) {
                    $table->longText('video')
                        ->nullable()
                        ->after('instructions');
                }
            });
        }

        if (Schema::hasColumn('products', 'extra_description') && Schema::hasColumn('products', 'instructions')) {
            DB::table('products')
                ->whereNull('instructions')
                ->whereNotNull('extra_description')
                ->update([
                    'instructions' => DB::raw('extra_description'),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $dropInstructions = Schema::hasColumn('products', 'instructions');
        $dropVideo = Schema::hasColumn('products', 'video');

        if (! $dropInstructions && ! $dropVideo) {
            return;
        }

        Schema::table('products', function (Blueprint $table) use ($dropInstructions, $dropVideo): void {
            if ($dropVideo) {
                $table->dropColumn('video');
            }

            if ($dropInstructions) {
                $table->dropColumn('instructions');
            }
        });
    }
};

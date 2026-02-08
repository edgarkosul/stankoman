<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Добавляем dimension в attributes
        Schema::table('attributes', function (Blueprint $table) {
            $table->string('dimension')
                ->nullable()
                ->after('unit_id');
        });

        // 2) Pivot attribute_unit
        Schema::create('attribute_unit', function (Blueprint $table) {
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('unit_id');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->primary(['attribute_id', 'unit_id']);

            $table->foreign('attribute_id')
                ->references('id')->on('attributes')
                ->cascadeOnDelete();

            $table->foreign('unit_id')
                ->references('id')->on('units')
                ->restrictOnDelete();
        });

        // 3) Перенос текущей связи unit_id → dimension + attribute_unit
        $attributes = DB::table('attributes')
            ->whereNotNull('unit_id')
            ->get(['id', 'unit_id', 'dimension']);

        if ($attributes->isEmpty()) {
            return;
        }

        $unitIds = $attributes->pluck('unit_id')->filter()->unique()->all();

        $units = DB::table('units')
            ->whereIn('id', $unitIds)
            ->get(['id', 'dimension'])
            ->keyBy('id');

        $now = now();

        foreach ($attributes as $attr) {
            $unit = $units[$attr->unit_id] ?? null;

            // Проставляем dimension у атрибута, если его ещё нет
            if ($unit && $unit->dimension && ! $attr->dimension) {
                DB::table('attributes')
                    ->where('id', $attr->id)
                    ->update(['dimension' => $unit->dimension]);
            }

            // Создаём pivot-запись (1:1 с существующим unit_id)
            if ($unit) {
                DB::table('attribute_unit')->updateOrInsert(
                    [
                        'attribute_id' => $attr->id,
                        'unit_id'      => $attr->unit_id,
                    ],
                    [
                        'is_default' => true,
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_unit');

        Schema::table('attributes', function (Blueprint $table) {
            $table->dropColumn('dimension');
        });
    }
};

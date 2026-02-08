<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $name): bool
    {
        $db = DB::getDatabaseName();
        return (bool) DB::selectOne(
            "SELECT 1 FROM information_schema.statistics
             WHERE table_schema=? AND table_name=? AND index_name=? LIMIT 1",
            [$db, $table, $name]
        );
    }

    public function up(): void
    {
        // новые поля: физика и полная конвертация
        Schema::table('units', function (Blueprint $t) {
            if (!Schema::hasColumn('units', 'dimension')) {
                $t->string('dimension')->nullable()->after('symbol'); // length, area, volume, flow, pressure, mass, force, power, torque, speed, energy, temperature, frequency, dimensionless
            }
            if (!Schema::hasColumn('units', 'base_symbol')) {
                $t->string('base_symbol')->nullable()->after('dimension'); // m, m², m³/s, Pa, kg, N, W, N·m, K, ...
            }
            if (!Schema::hasColumn('units', 'si_offset')) {
                $t->decimal('si_offset', 20, 10)->default(0)->after('si_factor'); // для °C: +273.15
            }
        });

        // полезные индексы и уникальность в рамках размерности
        if (!$this->indexExists('units', 'units_dimension_symbol_unique')) {
            Schema::table('units', fn (Blueprint $t) => $t->unique(['dimension','symbol'], 'units_dimension_symbol_unique'));
        }
        if (!$this->indexExists('units', 'units_dimension_base_idx')) {
            Schema::table('units', fn (Blueprint $t) => $t->index(['dimension','base_symbol'], 'units_dimension_base_idx'));
        }
    }

    public function down(): void
    {
        // индексы
        foreach (['units_dimension_symbol_unique','units_dimension_base_idx'] as $idx) {
            if ($this->indexExists('units', $idx)) {
                Schema::table('units', fn (Blueprint $t) => $t->dropIndex($idx));
            }
        }

        // поля (безопасно можно оставить; если нужно — удалим)
        Schema::table('units', function (Blueprint $t) {
            if (Schema::hasColumn('units', 'si_offset'))   $t->dropColumn('si_offset');
            if (Schema::hasColumn('units', 'base_symbol')) $t->dropColumn('base_symbol');
            if (Schema::hasColumn('units', 'dimension'))   $t->dropColumn('dimension');
        });
    }
};

<?php

// database/migrations/2025_10_12_000000_schema_unify_options_and_si.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function usesMySqlDriver(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function indexExists(string $table, string $name): bool
    {
        return Schema::hasIndex($table, $name);
    }

    private function fkExists(string $table, string $name): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    public function up(): void
    {
        // 1) product_attribute_values: SI-поля (для числовых атрибутов)
        Schema::table('product_attribute_values', function (Blueprint $t) {
            if (! Schema::hasColumn('product_attribute_values', 'value_si')) {
                $t->decimal('value_si', 28, 10)->nullable()->after('value_number');
            }
            if (! Schema::hasColumn('product_attribute_values', 'value_min_si')) {
                $t->decimal('value_min_si', 28, 10)->nullable()->after('value_si');
            }
            if (! Schema::hasColumn('product_attribute_values', 'value_max_si')) {
                $t->decimal('value_max_si', 28, 10)->nullable()->after('value_min_si');
            }
        });
        if (! $this->indexExists('product_attribute_values', 'pav_value_si_idx')) {
            Schema::table('product_attribute_values', fn (Blueprint $t) => $t->index(['value_si'], 'pav_value_si_idx'));
        }
        if (! $this->indexExists('product_attribute_values', 'pav_value_min_si_idx')) {
            Schema::table('product_attribute_values', fn (Blueprint $t) => $t->index(['value_min_si'], 'pav_value_min_si_idx'));
        }
        if (! $this->indexExists('product_attribute_values', 'pav_value_max_si_idx')) {
            Schema::table('product_attribute_values', fn (Blueprint $t) => $t->index(['value_max_si'], 'pav_value_max_si_idx'));
        }

        // 2) attribute_options: уникальная пара (id, attribute_id) — база для составного FK
        if (! $this->indexExists('attribute_options', 'ao_id_attr_unique')) {
            Schema::table('attribute_options', fn (Blueprint $t) => $t->unique(['id', 'attribute_id'], 'ao_id_attr_unique'));
        }
        if (! $this->indexExists('attribute_options', 'ao_attr_idx')) {
            Schema::table('attribute_options', fn (Blueprint $t) => $t->index(['attribute_id'], 'ao_attr_idx'));
        }

        // 3) product_attribute_option: индексы и уникальность
        if (! $this->indexExists('product_attribute_option', 'pao_prod_idx')) {
            Schema::table('product_attribute_option', fn (Blueprint $t) => $t->index(['product_id'], 'pao_prod_idx'));
        }
        if (! $this->indexExists('product_attribute_option', 'pao_attr_idx')) {
            Schema::table('product_attribute_option', fn (Blueprint $t) => $t->index(['attribute_id'], 'pao_attr_idx'));
        }
        if (! $this->indexExists('product_attribute_option', 'pao_opt_idx')) {
            Schema::table('product_attribute_option', fn (Blueprint $t) => $t->index(['attribute_option_id'], 'pao_opt_idx'));
        }
        if (! $this->indexExists('product_attribute_option', 'pao_unique_product_option')) {
            Schema::table('product_attribute_option', fn (Blueprint $t) => $t->unique(['product_id', 'attribute_option_id'], 'pao_unique_product_option'));
        }

        // 4) product_attribute_option: составной FK (attribute_option_id, attribute_id) → attribute_options(id, attribute_id)
        if (! $this->indexExists('product_attribute_option', 'pao_attr_opt_idx')) {
            Schema::table('product_attribute_option', fn (Blueprint $t) => $t->index(['attribute_option_id', 'attribute_id'], 'pao_attr_opt_idx'));
        }
        if ($this->usesMySqlDriver() && ! $this->fkExists('product_attribute_option', 'fk_pao_option_attr')) {
            DB::statement('
                ALTER TABLE product_attribute_option
                ADD CONSTRAINT fk_pao_option_attr
                FOREIGN KEY (attribute_option_id, attribute_id)
                REFERENCES attribute_options (id, attribute_id)
                ON DELETE CASCADE
            ');
        }

        // ВАЖНО: Никаких переносов/обновлений данных тут не делаем.
    }

    public function down(): void
    {
        // Снимаем только то, что добавляли (SI-поля оставлю — они безопасны; сними, если точно нужно).

        if ($this->usesMySqlDriver() && $this->fkExists('product_attribute_option', 'fk_pao_option_attr')) {
            DB::statement('ALTER TABLE product_attribute_option DROP FOREIGN KEY fk_pao_option_attr');
        }

        foreach ([
            ['product_attribute_option', 'pao_unique_product_option'],
            ['product_attribute_option', 'pao_attr_opt_idx'],
            ['product_attribute_option', 'pao_prod_idx'],
            ['product_attribute_option', 'pao_attr_idx'],
            ['product_attribute_option', 'pao_opt_idx'],
            ['attribute_options', 'ao_id_attr_unique'],
            ['attribute_options', 'ao_attr_idx'],
            ['product_attribute_values', 'pav_value_si_idx'],
            ['product_attribute_values', 'pav_value_min_si_idx'],
            ['product_attribute_values', 'pav_value_max_si_idx'],
        ] as [$table, $index]) {
            if ($this->indexExists($table, $index)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
            }
        }

        // Если принципиально — раскомментируй удаление SI-полей:
        // Schema::table('product_attribute_values', function (Blueprint $t) {
        //     if (Schema::hasColumn('product_attribute_values','value_si')) $t->dropColumn('value_si');
        //     if (Schema::hasColumn('product_attribute_values','value_min_si')) $t->dropColumn('value_min_si');
        //     if (Schema::hasColumn('product_attribute_values','value_max_si')) $t->dropColumn('value_max_si');
        // });
    }
};

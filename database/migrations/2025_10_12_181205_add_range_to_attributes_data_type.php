<?php

// database/migrations/2025_10_12_140000_add_range_to_attributes_data_type.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function usesMySqlDriver(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function columnEnum(string $table, string $column): ?string
    {
        if (! $this->usesMySqlDriver()) {
            return null;
        }

        $db = DB::getDatabaseName();
        $row = DB::selectOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$db, $table, $column]
        );

        return $row->COLUMN_TYPE ?? null; // например: "enum('text','number','boolean')"
    }

    public function up(): void
    {
        if (! $this->usesMySqlDriver()) {
            return;
        }

        // Добавляем 'range' в attributes.data_type, если его ещё нет
        $enum = $this->columnEnum('attributes', 'data_type');
        if ($enum && ! str_contains($enum, "'range'")) {
            // итоговый список значений
            DB::statement("
                ALTER TABLE attributes
                MODIFY COLUMN data_type
                ENUM('text','number','boolean','range')
                NOT NULL
            ");
        }
    }

    public function down(): void
    {
        if (! $this->usesMySqlDriver()) {
            return;
        }

        // Возвращаем исходный набор значений (без 'range'), только если сейчас он расширенный
        $enum = $this->columnEnum('attributes', 'data_type');
        if ($enum && str_contains($enum, "'range'")) {
            DB::statement("
                ALTER TABLE attributes
                MODIFY COLUMN data_type
                ENUM('text','number','boolean')
                NOT NULL
            ");
        }
    }
};

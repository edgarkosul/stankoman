<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE `products`
            SET `warranty` = CASE
                WHEN `warranty` IN ('12', '24', '36', '60') THEN `warranty`
                WHEN `warranty` REGEXP '(^|[^0-9])12([^0-9]|$)' THEN '12'
                WHEN `warranty` REGEXP '(^|[^0-9])24([^0-9]|$)' THEN '24'
                WHEN `warranty` REGEXP '(^|[^0-9])36([^0-9]|$)' THEN '36'
                WHEN `warranty` REGEXP '(^|[^0-9])60([^0-9]|$)' THEN '60'
                ELSE NULL
            END
        SQL);

        DB::statement("ALTER TABLE `products` MODIFY `warranty` ENUM('12', '24', '36', '60') NULL");
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE `products` MODIFY `warranty` VARCHAR(255) NULL');
    }
};

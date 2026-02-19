<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function usesSqliteDriver(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS attribute_product_links');

        if ($this->usesSqliteDriver()) {
            DB::statement(<<<'SQL'
CREATE VIEW attribute_product_links AS
-- PAV: одна строка на (attribute_id, product_id)
SELECT
    ('pav:' || pav.attribute_id || ':' || pav.product_id) AS id,
    pav.attribute_id,
    pav.product_id,
    'pav'  AS source,
    pav.id AS pav_id,
    NULL   AS pao_option_ids,
    NULL   AS pao_values,
    pav.value_text,
    pav.value_number,
    pav.value_boolean,
    pav.value_min,
    pav.value_max
FROM product_attribute_values pav

UNION ALL

-- PAO: агрегируем все опции в одну строку на (attribute_id, product_id)
SELECT
    ('pao:' || pao.attribute_id || ':' || pao.product_id) AS id,
    pao.attribute_id,
    pao.product_id,
    'pao' AS source,
    NULL  AS pav_id,
    GROUP_CONCAT(ao.id, ',') AS pao_option_ids,
    GROUP_CONCAT(ao.value, ', ') AS pao_values,
    NULL AS value_text,
    NULL AS value_number,
    NULL AS value_boolean,
    NULL AS value_min,
    NULL AS value_max
FROM product_attribute_option pao
JOIN attribute_options ao ON ao.id = pao.attribute_option_id
GROUP BY pao.attribute_id, pao.product_id
SQL);

            return;
        }

        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW `attribute_product_links` AS
-- PAV: одна строка на (attribute_id, product_id)
SELECT
    MD5(CONCAT('pav:', pav.attribute_id, ':', pav.product_id)) AS id,
    pav.attribute_id,
    pav.product_id,
    'pav'  AS source,
    pav.id AS pav_id,
    NULL   AS pao_option_ids,
    NULL   AS pao_values,
    pav.value_text,
    pav.value_number,
    pav.value_boolean,
    pav.value_min,
    pav.value_max
FROM `product_attribute_values` pav

UNION ALL

-- PAO: агрегируем все опции в одну строку на (attribute_id, product_id)
SELECT
    MD5(CONCAT('pao:', pao.attribute_id, ':', pao.product_id)) AS id,
    pao.attribute_id,
    pao.product_id,
    'pao' AS source,
    NULL  AS pav_id,
    GROUP_CONCAT(ao.id   ORDER BY ao.sort_order, ao.id   SEPARATOR ',')   AS pao_option_ids,
    GROUP_CONCAT(ao.value ORDER BY ao.sort_order, ao.value SEPARATOR ', ') AS pao_values,
    NULL AS value_text,
    NULL AS value_number,
    NULL AS value_boolean,
    NULL AS value_min,
    NULL AS value_max
FROM `product_attribute_option` pao
JOIN `attribute_options` ao ON ao.id = pao.attribute_option_id
GROUP BY pao.attribute_id, pao.product_id;
SQL);
    }

    public function down(): void
    {
        // при откате можно просто удалить VIEW
        DB::statement('DROP VIEW IF EXISTS attribute_product_links');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Сводное представление:
        // - PAV: одна строка на (attribute_id, product_id)
        // - PAO: агрегируем все option.value в одну строку, одна строка на (attribute_id, product_id)
        DB::statement(<<<SQL
CREATE OR REPLACE VIEW `attribute_product_links` AS
-- PAV: по уникальному ключу в таблице и так одна запись на пару
SELECT
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

-- PAO: группируем по паре и склеиваем значения опций
SELECT
    pao.attribute_id,
    pao.product_id,
    'pao' AS source,
    NULL  AS pav_id,
    GROUP_CONCAT(ao.id ORDER BY ao.sort_order, ao.id SEPARATOR ',')      AS pao_option_ids,
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
        DB::statement('DROP VIEW IF EXISTS `attribute_product_links`');
    }
};

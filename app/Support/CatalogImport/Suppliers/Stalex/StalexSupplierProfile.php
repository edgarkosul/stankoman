<?php

namespace App\Support\CatalogImport\Suppliers\Stalex;

use App\Support\CatalogImport\Yml\YandexMarketFeedProfile;

/**
 * Профиль поставщика Stalex.
 *
 * Фид Stalex — это обычный Yandex Market YML (windows-1251), поэтому профиль
 * наследует всю логику YML-профиля и лишь переопределяет идентификаторы
 * поставщика/профиля и дефолтный URL «рабочего» фида с фотогалереей.
 *
 * @see StalexSupplierAdapter
 */
final class StalexSupplierProfile extends YandexMarketFeedProfile
{
    /**
     * «Рабочий» фид Stalex: <picture> = главное фото, а галерея вынесена
     * в нестандартный <param name="Фотогалерея">. Обычный фид
     * (yandex_4659718.php) галереи не содержит и здесь не используется.
     */
    public const DEFAULT_FEED_URL = 'https://www.stalex.ru/bitrix/catalog_export/yandex_498907.php';

    public function profileKey(): string
    {
        return 'stalex_yml';
    }

    public function profileName(): string
    {
        return 'Stalex YML (с фотогалереей)';
    }

    public function supplierKey(): string
    {
        return 'stalex';
    }

    public function defaultSourceUrl(): string
    {
        return self::DEFAULT_FEED_URL;
    }
}

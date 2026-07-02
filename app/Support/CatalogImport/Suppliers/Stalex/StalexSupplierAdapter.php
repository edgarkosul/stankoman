<?php

namespace App\Support\CatalogImport\Suppliers\Stalex;

use App\Support\CatalogImport\Yml\VendorModelOfferNameResolver;
use App\Support\CatalogImport\Yml\YandexMarketFeedAdapter;
use SimpleXMLElement;

/**
 * Специфичный адаптер поставщика Stalex.
 *
 * Фид Stalex — обычный Yandex Market YML, поэтому вся логика маппинга
 * (name/vendor.model/sku/params/video/price) переиспользуется из
 * {@see YandexMarketFeedAdapter}. Отличий ровно два:
 *
 *  1. Галерея. В штатном <picture> лежит только главное фото, а остальные
 *     изображения вынесены в нестандартный <param name="Фотогалерея"> —
 *     список URL через «, » (запятая + пробел). Мы дочитываем этот параметр
 *     и добавляем URL'ы в хвост списка картинок с дедупом по имени файла.
 *  2. Мусор в <description>: незакрытые CMS-плейсхолдеры вида #BLOCK_1#,
 *     #BLOCK_2#, #BLOCK_SLIDER_1# прямо в тексте — вычищаем.
 *
 * Кодировка windows-1251 обрабатывается уровнем ниже (YmlStreamParser читает
 * encoding из XML-пролога), поэтому здесь имя параметра уже в UTF-8.
 *
 * @see docs/stalex_driver.md
 */
final class StalexSupplierAdapter extends YandexMarketFeedAdapter
{
    /**
     * Точное имя параметра галереи (после декодирования cp1251 → UTF-8).
     * Подтверждено на живом фиде: единственное написание — «Фотогалерея».
     */
    private const GALLERY_PARAM_NAME = 'Фотогалерея';

    public function __construct(
        StalexSupplierProfile $profile = new StalexSupplierProfile,
        VendorModelOfferNameResolver $vendorModelOfferNameResolver = new VendorModelOfferNameResolver,
    ) {
        parent::__construct($profile, $vendorModelOfferNameResolver);
    }

    /**
     * Главное фото из <picture> + галерея из <param name="Фотогалерея">.
     *
     * Политика (docs §4 п.8): <picture> — главная картинка и идёт первой,
     * галерея добавляется после неё. Дедуп — по имени файла (последний
     * сегмент пути, url-декодированный), а не по полному URL: одно и то же
     * фото встречается под разными путями/хешами (docs §4 п.7).
     *
     * @return array<int, string>
     */
    protected function extractPictures(SimpleXMLElement $xml): array
    {
        $pictures = parent::extractPictures($xml);

        $seen = [];

        foreach ($pictures as $picture) {
            $seen[$this->pictureDedupeKey($picture)] = true;
        }

        foreach ($this->galleryUrls($xml) as $url) {
            $key = $this->pictureDedupeKey($url);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $pictures[] = $url;
        }

        return $pictures;
    }

    /**
     * Как в штатном адаптере, плюс чистка незакрытых CMS-плейсхолдеров
     * #BLOCK...# (docs §5): #BLOCK_1#, #BLOCK_2#, #BLOCK_SLIDER_1# и т.п.
     *
     * @param  array<int, string>  $pictures
     */
    protected function extractDescription(SimpleXMLElement $xml, array $pictures): ?string
    {
        $description = parent::extractDescription($xml, $pictures);

        if ($description === null) {
            return null;
        }

        // Удаляем плейсхолдеры вида #BLOCK_1#, #BLOCK_SLIDER_1# и любые #BLOCK...#.
        $cleaned = preg_replace('/#BLOCK[A-Z0-9_]*#/iu', '', $description) ?? $description;

        // Схлопываем пустоты, оставшиеся после удаления плейсхолдеров.
        $cleaned = preg_replace('/[ \t]{2,}/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/(\R){3,}/u', "\n\n", $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Разбирает <param name="Фотогалерея"> в список URL.
     *
     * Разделитель — «, » (запятая + пробел), поэтому после split по запятой
     * каждый элемент обязательно trim'ится, иначе в URL попадёт ведущий пробел.
     * Сами URL не «чиним» — %20 в именах файлов оставляем как есть (docs §4 п.5).
     *
     * @return array<int, string>
     */
    private function galleryUrls(SimpleXMLElement $xml): array
    {
        $raw = $this->galleryParamValue($xml);

        if ($raw === null) {
            return [];
        }

        $urls = [];

        foreach (explode(',', $raw) as $candidate) {
            $url = trim($candidate);

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function galleryParamValue(SimpleXMLElement $xml): ?string
    {
        foreach ($xml->param as $paramNode) {
            $name = trim((string) ($paramNode['name'] ?? ''));

            if ($name !== self::GALLERY_PARAM_NAME) {
                continue;
            }

            $value = trim((string) $paramNode);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * Ключ дедупа = имя файла (последний сегмент пути), url-декодированное
     * и в нижнем регистре. Так «DSC08350%20bl.jpg» и «DSC08350 bl.jpg»
     * под разными хеш-путями считаются одним файлом.
     */
    private function pictureDedupeKey(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            $path = $url;
        }

        $filename = basename($path);
        $decoded = rawurldecode($filename);

        return mb_strtolower($decoded !== '' ? $decoded : $url);
    }
}

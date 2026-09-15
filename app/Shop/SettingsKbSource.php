<?php

namespace App\Shop;

use App\Services\Kb\Contracts\KbSource;
use App\Services\Kb\Data\KbDocument;

/**
 * Реквизиты магазина из настроек — один синтетический документ базы знаний.
 *
 * Отвечает на B2B-вопросы «пришлите реквизиты», «какой у вас ИНН», «на какой
 * счёт платить» с первого дня, без статей от заказчика. Берётся оттуда же,
 * откуда их берут письма и документы магазина, — из `company.*` (настройки
 * раскладывает туда SettingsServiceProvider), а не с HTML-страницы, где их
 * однажды набрали руками.
 *
 * Свежесть держит ночной `ai:kb-reindex`, а не наблюдатель за настройками:
 * воркер очереди читает конфиг один раз при запуске, и переиндексация из него
 * взяла бы прежние реквизиты. Процесс планировщика стартует заново и видит
 * текущие.
 */
final class SettingsKbSource implements KbSource
{
    public const NAME = 'intertooler-settings';

    public const DOCUMENT_KEY = 'requisites';

    private const TITLE = 'Реквизиты и данные организации';

    public function __construct(
        private readonly string $shopName,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @return iterable<KbDocument>
     */
    public function documents(): iterable
    {
        $text = $this->text();

        if ($text === '') {
            return;
        }

        yield new KbDocument(
            key: self::DOCUMENT_KEY,
            title: self::TITLE,
            breadcrumb: array_values(array_filter([$this->shopName, self::TITLE])),
            text: $text,
        );
    }

    /**
     * Разделы — заголовками `##`: чанкер режет по ним, и вопрос «какой БИК»
     * находит фрагмент с префиксом «Банковские реквизиты», а не весь документ.
     */
    private function text(): string
    {
        $company = (array) config('company', []);
        $bank = is_array($company['bank'] ?? null) ? $company['bank'] : [];
        $vat = (int) config('settings.product.stavka_nds');

        $sections = [
            'Организация' => [
                'Наименование' => $company['legal_name'] ?? null,
                'ИНН' => $company['inn'] ?? null,
                'КПП' => $company['kpp'] ?? null,
                'ОГРН' => $company['ogrn'] ?? null,
                'ОГРНИП' => $company['ogrnip'] ?? null,
                'Юридический адрес' => $company['legal_addr'] ?? null,
                'Адрес для корреспонденции' => $company['correspondence_addr'] ?? null,
            ],
            'Банковские реквизиты' => [
                'Банк' => $bank['name'] ?? null,
                'БИК' => $bank['bik'] ?? null,
                'Расчётный счёт' => $bank['rs'] ?? null,
                'Корреспондентский счёт' => $bank['ks'] ?? null,
            ],
            'Контакты' => [
                'Телефон' => $company['phone'] ?? null,
                'Электронная почта' => $company['public_email'] ?? null,
                'Сайт' => $company['site_url'] ?? null,
            ],
            // Та же формулировка, что под ценой на карточке товара.
            'НДС' => [
                'Ставка НДС' => $vat > 0 ? $vat.'%, цены на сайте указаны с НДС (в том числе)' : null,
            ],
        ];

        $blocks = [];

        foreach ($sections as $heading => $fields) {
            $lines = [];

            foreach ($fields as $label => $value) {
                $value = is_scalar($value) ? trim((string) $value) : '';

                // Пустое поле не пишем вовсе: строка «ОГРН:» без значения
                // модель прочтёт как «ОГРН у магазина нет».
                if ($value !== '') {
                    $lines[] = $label.': '.$value;
                }
            }

            if ($lines !== []) {
                $blocks[] = '## '.$heading."\n\n".implode("\n", $lines);
            }
        }

        return implode("\n\n", $blocks);
    }
}

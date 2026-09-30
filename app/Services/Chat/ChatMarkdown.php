<?php

namespace App\Services\Chat;

use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Text;

/**
 * Разметка в ленте чата: ответ бота и реплика оператора.
 *
 * Без неё ссылку на товар бот отдаёт голым адресом на девяносто символов,
 * а звёздочки покупатель видит буквально. Настоящий парсер решает обе
 * задачи; снимать разметку регекспами не нужно, а такие регекспы по своей
 * природе требуют заплаток вечно.
 *
 * ЧТО РАЗРЕШЕНО И ПОЧЕМУ ИМЕННО ЭТО
 * Абзацы, списки, жирный, курсив и ссылки. Заголовки в ответе на два-три
 * предложения — шум; таблица в панели шириной 380 пикселей нечитаема;
 * картинка это ещё и запрос на чужой сервер с IP посетителя. Всё лишнее
 * не запрещается промптом, а разбирается здесь: промпт задаёт норму,
 * а гарантирует её код.
 *
 * ГЛАВНЫЙ РИСК — НЕ XSS, А ССЫЛКА С ПОДМЕНЁННЫМ ТЕКСТОМ
 * Сырой HTML экранируется (`html_input: escape`), `javascript:` режется
 * (`allow_unsafe_links: false`) — это документированная безопасная
 * конфигурация. Но `[Оплатить заказ](http://evil.ru)` рендерится идеально
 * безопасным HTML и потому опаснее голого адреса: покупатель видит слова,
 * а не то, куда идёт. Источник не модель сама по себе, а то, что она
 * проглотила: описание товара от поставщика, статья базы знаний. Поэтому
 * **кликабельны только свои ссылки**, чужой адрес показывается текстом
 * целиком, вместе с доменом.
 */
final class ChatMarkdown
{
    /**
     * Теги, которые доживают до ленты.
     *
     * Список белый, а не чёрный: добавление расширения в будущем не должно
     * молча протащить в ленту новый тег.
     */
    private const ALLOWED_TAGS = '<p><br><strong><em><ul><ol><li><a><code>';

    private ?MarkdownConverter $converter = null;

    public function __construct(
        /** Свой хост: ссылки только на него остаются кликабельными. */
        private readonly string $appUrl,
        /**
         * Другие хосты магазина. Поддомены каждого из них тоже свои.
         *
         * @var list<string>
         */
        private readonly array $ownHosts = [],
    ) {}

    /** Готовый HTML для вставки в ленту. */
    public function toHtml(string $text): HtmlString
    {
        $text = trim($text);

        if ($text === '') {
            return new HtmlString('');
        }

        $html = (string) $this->converter()->convert($text);

        // Заголовки, цитаты, блоки кода и картинки: тег убираем, текст
        // оставляем. Картинка теряется целиком — она и была нежелательной.
        $html = strip_tags($html, self::ALLOWED_TAGS);

        return new HtmlString(trim($html));
    }

    /**
     * Текст без разметки — для мест, где HTML не нужен: колонка «последняя
     * реплика», письма, тема заявки. Иначе покупателю в почту уезжает
     * «**402 659 руб.**», а в списке диалогов — звёздочки вперемешку.
     */
    public function toPlainText(string $text): string
    {
        $html = $this->toHtml($text)->toHtml();

        if ($html === '') {
            return '';
        }

        /*
         * Сначала убираем переносы, которые CommonMark ставит МЕЖДУ тегами
         * для читаемости разметки: без этого каждый пункт списка получал бы
         * лишнюю пустую строку — свою и нашу.
         */
        $html = preg_replace('~>\s+<~', '><', $html) ?? $html;

        // Абзац — пустая строка, пункт списка и перенос — одна строка.
        // Конец списка добавляет вторую: дальше идёт новый абзац.
        $html = preg_replace('~</p>~i', "\n\n", $html) ?? $html;
        $html = preg_replace('~</li>~i', "\n", $html) ?? $html;
        $html = preg_replace('~</(ul|ol)>~i', "\n", $html) ?? $html;
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter !== null) {
            return $this->converter;
        }

        $environment = new Environment([
            // Любой сырой HTML во входе экранируется — вместе с ним закрыт
            // и весь класс XSS через разметку модели.
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br>\n"],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);

        // Голый адрес модель отдаёт до сих пор. Автоссылка делает его
        // кликабельным, а проверка хоста ниже решает, останется ли он ссылкой.
        $environment->addExtension(new AutolinkExtension);

        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            foreach ($event->getDocument()->iterator() as $node) {
                if (! $node instanceof Link) {
                    continue;
                }

                if (! $this->isOwnHost($node->getUrl())) {
                    $this->degradeToText($node);

                    continue;
                }

                /*
                 * Своя ссылка открывается в новой вкладке: чат живёт в углу
                 * страницы, и уход по ссылке в том же окне сворачивает
                 * разговор ровно в тот момент, когда покупатель пошёл
                 * смотреть товар. `noopener` — обязательный спутник `_blank`.
                 */
                $node->data->set('attributes/target', '_blank');
                $node->data->set('attributes/rel', 'noopener');
            }
        });

        return $this->converter = new MarkdownConverter($environment);
    }

    /**
     * Чужая ссылка превращается в текст, где виден адрес.
     *
     * Не удаляется: покупателю мог понадобиться именно этот адрес — сайт
     * производителя, транспортной компании. Но кликом он не становится,
     * и подпись больше не скрывает, куда ведёт.
     */
    private function degradeToText(Link $link): void
    {
        $label = '';

        foreach ($link->children() as $child) {
            if ($child instanceof Text) {
                $label .= $child->getLiteral();
            }
        }

        $label = trim($label);
        $url = $link->getUrl();

        $link->replaceWith(new Text(
            $label === '' || $label === $url ? $url : $label.' ('.$url.')'
        ));
    }

    private function isOwnHost(string $url): bool
    {
        // Относительные адреса — заведомо свои.
        if (! preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url)) {
            return ! str_starts_with($url, '//');
        }

        $host = parse_url($url, PHP_URL_HOST);
        $own = parse_url($this->appUrl, PHP_URL_HOST);

        if (! is_string($host) || ! is_string($own)) {
            return false;
        }

        $host = strtolower(ltrim($host, '.'));

        foreach ([$own, ...$this->ownHosts] as $candidate) {
            $candidate = strtolower(ltrim(trim((string) $candidate), '.'));

            if ($candidate === '') {
                continue;
            }

            // Поддомены своего хоста тоже свои.
            if ($host === $candidate || str_ends_with($host, '.'.$candidate)) {
                return true;
            }
        }

        return false;
    }
}

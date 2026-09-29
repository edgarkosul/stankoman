<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Три правки текстов страниц по просьбе владельца (анкета от 27.09.2026).
 *
 * 1. «Пользовательское соглашение», п. 8.1: в опубликованный текст попала
 *    приписка из черновика — «…поэтому итоговые процедуры лучше дополнительно
 *    синхронизировать с вашей фактической схемой продаж и внутренними
 *    правилами возврата». Обращение к владельцу магазина, которое полгода
 *    читали покупатели. Убираем хвост, сама правовая фраза остаётся.
 *
 * 2. «Контакты»: на экране написано sales@intertooler.ru, а ссылка открывала
 *    письмо на r_kodachenko@mail.ru. Чинится адрес ссылки; блок реквизитов
 *    ниже (там тот же адрес) НЕ трогаем — его целиком заменяет миграция
 *    ветки бота, и любая правка внутри сломала бы ей поиск фрагмента.
 *    После её выката там встанет `company.public_email`, то есть sales@.
 *
 * 3. «Доставка и оплата»: разделы «Доставка», «Основные способы доставки»
 *    и «Оплата» были жирными абзацами, а не заголовками. Человеку разницы
 *    почти нет, а поиск и бот разбирают страницу на части по заголовкам:
 *    жирный абзац за заголовок не считается намеренно (иначе «ИП Кодаченко»
 *    жирным тоже стал бы разделом).
 *
 * Меняем ТОЛЬКО точный фрагмент, встреченный ровно один раз: страницы правят
 * в админке, и если текст на бою уже не тот, чужую правку не трогаем —
 * пишем предупреждение и идём дальше.
 *
 * Базу знаний бота это не переиндексирует (запись мимо модели): после выката
 * ветки бота — `php artisan ai:kb-reindex`, иначе догонит ночной проход.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string}> [slug, было, стало] */
    private const REPLACEMENTS = [
        [
            'terms',
            '; при этом в 2026 году регулирование этой части уже затронуто постановлением Конституционного Суда, поэтому итоговые процедуры лучше дополнительно синхронизировать с вашей фактической схемой продаж и внутренними правилами возврата.',
            '.',
        ],
        [
            'kontakty',
            '<a href="mailto:r_kodachenko@mail.ru" target="_blank" rel="noopener noreferrer nofollow">sales@intertooler.ru</a>',
            '<a href="mailto:sales@intertooler.ru" target="_blank" rel="noopener noreferrer nofollow">sales@intertooler.ru</a>',
        ],
        ['dostavka-i-oplata', '<p><strong>Доставка:</strong></p>', '<h2>Доставка</h2>'],
        ['dostavka-i-oplata', '<p><strong>Основные способы доставки:</strong></p>', '<h2>Основные способы доставки</h2>'],
        ['dostavka-i-oplata', '<p> <strong>Оплата:</strong></p>', '<h2>Оплата</h2>'],
    ];

    public function up(): void
    {
        foreach (self::REPLACEMENTS as [$slug, $from, $to]) {
            $this->swap($slug, $from, $to);
        }
    }

    /**
     * Обратной правки нет намеренно: возвращать в соглашение черновую
     * приписку, а в ссылку — чужой адрес незачем.
     */
    public function down(): void {}

    private function swap(string $slug, string $from, string $to): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        $page = DB::table('pages')->where('slug', $slug)->first(['id', 'content']);
        $content = (string) ($page->content ?? '');
        $pattern = $this->pattern($from);

        if ($page === null || preg_match_all($pattern, $content) !== 1) {
            $message = "Страница «{$slug}»: ожидаемый фрагмент не найден, текст не тронут — поправьте в админке.";

            Log::warning($message);

            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                fwrite(STDOUT, '  '.$message.PHP_EOL);
            }

            return;
        }

        DB::table('pages')->where('id', $page->id)->update([
            'content' => preg_replace_callback($pattern, static fn (): string => $to, $content, 1),
            'updated_at' => now(),
        ]);
    }

    /**
     * Точное совпадение фрагмента, но пробел в нём — обычный или неразрывный.
     *
     * Редактор ставит неразрывные пробелы там, где их не ждёшь: в «Доставке
     * и оплате» заголовок «Оплата» начинается именно с него, и сравнение
     * байт в байт промахнулось бы молча.
     */
    private function pattern(string $fragment): string
    {
        return '/'.str_replace(' ', '[ \x{00A0}]', preg_quote($fragment, '/')).'/u';
    }
};

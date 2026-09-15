<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Реквизиты и режим работы на страницах берутся из настроек, а не набраны руками.
 *
 * «Контакты» показывали реквизиты ООО «Михайлов день», хотя магазин работает
 * от ИП из `company.*` — по нему выставляются документы, и бот, прочитав
 * страницу, называл покупателю чужой ИНН и счёт. «О компании» держала свой
 * график (9–17), расходившийся с шапкой (9–18). Оба фрагмента меняются на блоки,
 * которые рисуют текущее значение настроек: «Реквизиты продавца» с банковскими
 * реквизитами и «Режим работы».
 *
 * Меняем ТОЛЬКО точный фрагмент, встреченный ровно один раз. Страницы правят
 * в админке, и если текст на бою уже не тот, что был при написании миграции,
 * чужую правку не трогаем — пишем предупреждение и идём дальше.
 *
 * Базу знаний бота это не переиндексирует (запись мимо модели, наблюдателя
 * нет): после выката — `php artisan ai:kb-reindex`, иначе догонит ночной проход.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> slug → [было, стало] */
    private const REPLACEMENTS = [
        'kontakty' => [
            '<p>ООО &quot;МИХАЙЛОВ ДЕНЬ &quot;</p><p>Юридический адрес: 350902, РОССИЯ, КРАСНОДАРСКИЙ КРАЙ, Г.О. ГОРОД КРАСНОДАР, Г КРАСНОДАР, УЛ АНДРЕЕВСКАЯ, Д. 2</p><p>Фактический адрес: 350902, РОССИЯ, КРАСНОДАРСКИЙ КРАЙ, Г.О. ГОРОД КРАСНОДАР, Г КРАСНОДАР, УЛ АНДРЕЕВСКАЯ, Д. 2</p><p>ИНН 2311386255</p><p>КПП 231101001</p><p>ОГРН 1252300058141</p><p>Расчётный счёт 40702 810 7 3074 0005761</p><p>Телефон +79002468660</p><p>Е-мэйл: r_kodachenko@mail.ru</p><hr><h2><strong>Банк получателя</strong></h2><p>Наименование КРАСНОДАРСКОЕ ОТДЕЛЕНИЕ N8619 ПАО СБЕРБАНК</p><p>БИК 040349602</p><p>Корсчёт 30101 810 1 0000 0000602</p><p>ИНН 7707083893</p><p>КПП 231043001</p><p><br></p><p></p>',
            '<div data-type="customBlock" data-config="{&quot;show_bank&quot;:true}" data-id="seller-requisites"></div>',
        ],
        'o-kompanii' => [
            '<p>График работы:</p><p>Пн - Пт: 9:00 - 17:00</p><p>Сб - Вс: отгрузка по предварительной договоренности</p>',
            '<div data-type="customBlock" data-config="null" data-id="work-schedule"></div>',
        ],
    ];

    public function up(): void
    {
        foreach (self::REPLACEMENTS as $slug => [$before, $after]) {
            $this->swap($slug, $before, $after);
        }
    }

    public function down(): void
    {
        foreach (self::REPLACEMENTS as $slug => [$before, $after]) {
            $this->swap($slug, $after, $before);
        }
    }

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
     * Редактор ставит неразрывные пробелы в группы цифр: на «Контактах» номер
     * счёта «40702 810 7 3074 0005761» выглядит одинаково, а байты другие.
     * Сравнение байт в байт на такой странице промахнулось бы молча.
     */
    private function pattern(string $fragment): string
    {
        return '/'.str_replace(' ', '[ \x{00A0}]', preg_quote($fragment, '/')).'/u';
    }
};

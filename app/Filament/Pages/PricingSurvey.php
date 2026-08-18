<?php

namespace App\Filament\Pages;

use App\Models\SurveyResponse;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Throwable;
use UnitEnum;

/**
 * Анкета по ценам: восемь вопросов о том, как импорт цен из Excel должен
 * вести себя в спорных случаях. Открыта только админам панели.
 */
class PricingSurvey extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static string|UnitEnum|null $navigationGroup = 'Экспорт/Импорт';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Вопросы по ценам';

    protected static ?string $title = 'Восемь вопросов о ценах';

    protected static ?string $slug = 'pricing-survey';

    protected string $view = 'filament.pages.pricing-survey';

    /** @var array<string, string> */
    public array $saved = [];

    public ?string $savedAt = null;

    public ?string $savedBy = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->isFilamentAdmin() === true;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            return SurveyResponse::latestFor(SurveyResponse::SURVEY_PRICING) === null ? 'новое' : null;
        } catch (Throwable) {
            // Таблица ещё не смигрирована — значок не должен ронять всю панель.
            return null;
        }
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public function mount(): void
    {
        $this->loadSavedResponse();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['questions' => self::questions()];
    }

    /**
     * Вопросы анкеты. Порядок массива = порядок экранов.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function questions(): array
    {
        return [
            [
                'id' => 'empty_cell',
                'short' => 'Пустая клетка в файле',
                'title' => 'Если клетка в файле пустая — что мне с ней делать?',
                'now' => 'Сейчас пустая клетка означает «стереть значение на сайте». Именно из-за этого 13 августа загрузка и оборвалась.',
                'options' => [
                    [
                        'key' => 'А',
                        'title' => 'Оставить как было',
                        'desc' => 'Пустая клетка = «я это поле не трогал». Чтобы обнулить значение, вы пишете 0.',
                        'effect' => 'Сделаю так, что лишние колонки можно спокойно чистить — на сайте ничего не пропадёт.',
                        'recommended' => true,
                    ],
                    [
                        'key' => 'Б',
                        'title' => 'Стереть значение на сайте',
                        'desc' => 'Пустая клетка = «удалить то, что записано».',
                        'effect' => 'Я проверил ваш файл от 13 августа: у 7 товаров стёрлась бы оптовая цена — например, 170 780 ₽ у S-7.5DF230.',
                    ],
                ],
            ],
            [
                'id' => 'empty_calc',
                'short' => 'Пустые расчётные колонки',
                'title' => 'Вы очистили «Курс валюты», «Опт, руб» и «Цена на сайт». Откуда мне брать цену?',
                'now' => 'Сейчас без курса цена не считается вообще, и загрузка обрывается с ошибкой — как 13 августа.',
                'options' => [
                    [
                        'key' => 'А',
                        'title' => 'Считать по курсу ЦБ',
                        'desc' => 'Предлагаю так: Цена опт × курс ЦБ = «Опт, руб», дальше × Наценка = «Цена на сайт».',
                        'calc' => '1155 $ × 83,81 = 96 801 ₽ → × 1,2 = <b>116 161 ₽</b>',
                        'recommended' => true,
                    ],
                    [
                        'key' => 'Б',
                        'title' => 'Брать курс, который стоит у товара',
                        'desc' => 'Тот курс, что уже записан в карточке товара.',
                        'calc' => 'у этих товаров курс 1,00 → 1155 × 1 × 1,2 = <b>1 386 ₽</b>',
                    ],
                ],
            ],
            [
                'id' => 'price_priority',
                'short' => 'Цена на сайт или формула',
                'title' => 'В файле заполнены и «Цена на сайт», и «Цена опт + Наценка». Что мне считать главным?',
                'now' => 'Сейчас записывается та цена, что стоит в колонке «Цена на сайт».',
                'options' => [
                    [
                        'key' => 'А',
                        'title' => 'То, что вы поменяли руками',
                        'desc' => 'Предлагаю сравнивать файл с текущими данными: поменяли опт или наценку — пересчитаю цену; поменяли цену на сайте — оставлю вашу цифру.',
                        'effect' => 'Работает и так и так, заранее ничего стирать не надо.',
                        'recommended' => true,
                    ],
                    [
                        'key' => 'Б',
                        'title' => 'Всегда формула',
                        'desc' => 'Опт × курс × наценка — и точка.',
                        'effect' => 'Тогда своя цена в колонке «Цена на сайт» не удержится: расчёт её перезапишет.',
                    ],
                    [
                        'key' => 'В',
                        'title' => 'Всегда «Цена на сайт» из файла',
                        'desc' => 'Что написано в колонке, то и будет на сайте.',
                        'effect' => 'Тогда изменение наценки ни на что не повлияет, пока вы не сотрёте цену в колонке.',
                    ],
                ],
            ],
            [
                'id' => 'discount_priority',
                'short' => 'Цена со скидкой или процент',
                'title' => '«Цена со скидкой» и «Скидка в %» — что мне считать главным?',
                'now' => 'В карточке товара уже так, как вы просите: пишете процент — считается цена, пишете цену — считается процент. А в Excel всегда главнее процент, своя цена со скидкой не сохраняется.',
                'options' => [
                    [
                        'key' => 'А',
                        'title' => 'То, что вы поменяли руками',
                        'desc' => 'Как в предыдущем вопросе. Если поменяли оба поля сразу — беру за главное «Цену со скидкой».',
                        'effect' => 'Сделаю так, что Excel будет вести себя ровно как карточка товара на сайте.',
                        'recommended' => true,
                    ],
                    [
                        'key' => 'Б',
                        'title' => 'Всегда «Цена со скидкой»',
                        'desc' => 'Процент только показывается покупателю, но ничего не задаёт.',
                        'effect' => 'Предупреждаю: поменяв один процент, вы не увидите изменений — в выгрузке цена со скидкой всегда заполнена и перебьёт его.',
                    ],
                    [
                        'key' => 'В',
                        'title' => 'Всегда «Скидка в %»',
                        'desc' => 'Как сейчас: цену со скидкой считаю из процента.',
                        'effect' => 'Тогда вписать свою цену со скидкой через Excel будет нельзя — только в карточке товара.',
                    ],
                ],
            ],
            [
                'id' => 'discount_who',
                'short' => 'Кому считать скидку',
                'title' => 'Кому показывать и считать цену со скидкой?',
                'now' => 'Я проверил: в каталоге и в корзине скидку видят все, но в итог заказа она попадает только у зарегистрированных (или если человек ставит галку «завести личный кабинет»). Получается, гость видит скидку, а платит полную цену.',
                'options' => [
                    [
                        'key' => 'А',
                        'title' => 'Скидка для всех',
                        'desc' => 'Гость покупает по цене со скидкой, регистрироваться не нужно.',
                        'effect' => 'Сведу корзину и итог заказа для всех покупателей.',
                    ],
                    [
                        'key' => 'Б',
                        'title' => 'Скидка только зарегистрированным',
                        'desc' => 'Как было задумано изначально.',
                        'effect' => 'Тогда гостю перестану показывать цену со скидкой и напишу «цена со скидкой — для зарегистрированных».',
                    ],
                ],
            ],
            [
                'id' => 'parser',
                'short' => 'Загрузка от поставщика',
                'title' => 'Загрузка прайса от поставщика и ваши цены',
                'now' => 'Здесь вы ошибаетесь, я проверил: такая загрузка перезаписывает «Цена на сайт» ценой поставщика, если при запуске не выбрать «Только выбранные поля» без «Цена». Опт, курс и наценку она не трогает вообще.',
                'options' => [
                    [
                        'key' => 'А',
                        'title' => 'Защитить ваши цены',
                        'desc' => 'Предлагаю правило: если у товара заполнены «Цена опт» и «Наценка» — прайс поставщика цену на сайте не трогает никогда.',
                        'effect' => 'Ваша наценка держится сама, о настройках при запуске думать не надо.',
                        'recommended' => true,
                    ],
                    [
                        'key' => 'Б',
                        'title' => 'Оставить как есть',
                        'desc' => 'Решать галочками при каждом запуске загрузки.',
                        'effect' => 'Забыли снять галку «Цена» — цены поставщика затрут вашу наценку.',
                    ],
                ],
            ],
            [
                'id' => 'cbr_column',
                'short' => 'Колонка «Обновлять по курсу ЦБ»',
                'title' => 'Новая колонка в Excel: «Обновлять по курсу ЦБ»',
                'now' => 'Сделаю так: ИСТИНА — цена сама пересчитывается по курсу ЦБ каждый день; ЛОЖЬ — курс заморожен, пока вы не поменяете его вручную. Если вы вписали курс своими руками, колонка сама станет ЛОЖЬ.',
                'options' => [
                    [
                        'key' => 'А',
                        'title' => 'Да, так и делаю',
                        'desc' => 'Колонка появится и в выгрузке, и в загрузке.',
                        'recommended' => true,
                    ],
                    [
                        'key' => 'Б',
                        'title' => 'Нет, нужно иначе',
                        'desc' => 'Опишу отдельно, как это должно работать.',
                        'effect' => 'Тогда колонку пока не добавляю и жду вашего описания.',
                    ],
                ],
            ],
            [
                'id' => 'sku',
                'short' => 'Артикул в карточке товара',
                'title' => 'Артикул в карточке товара',
                'now' => 'Я посмотрел, как у Ильи на kratonshop: там артикул стоит сразу под названием. У нас он справа, в одной строке с кнопками.',
                'options' => [
                    [
                        'key' => 'А',
                        'title' => 'Перенести под название',
                        'desc' => 'Сделаю как на kratonshop.',
                        'recommended' => true,
                    ],
                    [
                        'key' => 'Б',
                        'title' => 'Оставить как есть',
                        'desc' => 'Ничего не меняю.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $answers
     */
    public function submit(array $answers): void
    {
        $clean = [];

        foreach (self::questions() as $question) {
            $key = $answers[$question['id']] ?? null;

            $allowed = array_column($question['options'], 'key');

            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                Notification::make()
                    ->title('Не все вопросы отвечены')
                    ->body('Вернитесь к вопросу «'.$question['short'].'» и выберите вариант.')
                    ->warning()
                    ->send();

                return;
            }

            $clean[$question['id']] = $key;
        }

        SurveyResponse::query()->create([
            'survey' => SurveyResponse::SURVEY_PRICING,
            'user_id' => Auth::id(),
            'answers' => $clean,
        ]);

        $this->loadSavedResponse();

        Notification::make()
            ->title('Ответы отправлены')
            ->body('Спасибо! Я их получил и начинаю правки.')
            ->success()
            ->send();
    }

    private function loadSavedResponse(): void
    {
        $response = SurveyResponse::latestFor(SurveyResponse::SURVEY_PRICING);

        if (! $response instanceof SurveyResponse) {
            $this->saved = [];
            $this->savedAt = null;
            $this->savedBy = null;

            return;
        }

        $this->saved = array_map('strval', (array) $response->answers);
        $this->savedAt = $response->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');
        $this->savedBy = $response->user?->name ?? $response->user?->email;
    }
}

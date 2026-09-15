<?php

namespace App\Filament\Pages;

use App\Filament\Resources\KbArticles\KbArticleResource;
use App\Services\Kb\Data\KbGapCluster;
use App\Services\Kb\Data\KbGapQuestion;
use App\Services\Kb\Data\KbGapReport;
use App\Services\Kb\KbArticleDrafter;
use App\Services\Kb\KbGapAnalyzer;
use App\Services\Kb\KbVectorStore;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * «Пробелы в базе знаний» — очередь работ на неделю.
 *
 * Экран отвечает на вопрос «что писать дальше», и отвечает группами,
 * а не строками: «про возврат спрашивали 14 раз» — задача, а триста
 * отдельных вопросов — лента, которую никто не читает.
 *
 * Ни одного обращения к шлюзу: и сигналы, и векторы вопросов посчитаны
 * в момент ответа и лежат в переписке. Экран, открытие которого стоит
 * денег, открывали бы с опаской.
 */
class KbGaps extends Page
{
    /*
     * Иконки у пункта нет и быть не может: значок «звёздочек» висит на
     * группе «ИИ бот», а Filament запрещает иметь его одновременно
     * у группы и у её пунктов — и ловит это не проверкой, а исключением
     * прямо в шаблоне сайдбара, то есть падает вся админка.
     */
    protected static string|UnitEnum|null $navigationGroup = 'ИИ бот';

    /*
     * Второй пункт группы — по порядку работы: увидел пробел в диалогах,
     * посмотрел, о чём спрашивают чаще всего, пошёл писать статью.
     */
    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Пробелы';

    /*
     * Бейджа с числом у пункта намеренно нет. Он считался бы на каждой
     * странице админки, а главное — соперничал бы с бейджем «Диалогов»,
     * который значит «человек ждёт ответа прямо сейчас». Пробелы не срочны:
     * это работа на неделю, а не на минуту, и мигающая цифра рядом с ними
     * обесценила бы ту, что срочна.
     */

    protected static ?string $title = 'Пробелы в базе знаний';

    protected string $view = 'filament.pages.kb-gaps';

    /** Окно наблюдения в днях; пустая строка — за всё время. */
    #[Url]
    public string $period = '30';

    /** Один из ключей KbGapAnalyzer::SIGNALS; пустая строка — любой. */
    #[Url]
    public string $signal = '';

    /**
     * Разбор переписки — один раз за запрос, сколько бы раз шаблон
     * к нему ни обратился.
     */
    #[Computed]
    public function report(): KbGapReport
    {
        return app(KbGapAnalyzer::class)->report(
            days: $this->period === '' ? null : max(1, (int) $this->period),
            signal: array_key_exists($this->signal, KbGapAnalyzer::SIGNALS) ? $this->signal : null,
        );
    }

    /**
     * Собрать черновик статьи по группе и открыть его в форме.
     *
     * Вызов модели идёт синхронно, прямо в этом запросе, — и это осознанно.
     * Правило «LLM никогда не в FPM» защищает витрину: там воркеры на всех
     * покупателей, а ответ бота ждут секунды. Здесь кнопку жмёт один человек
     * несколько раз в неделю, и очередь с уведомлением стоила бы ему ожидания
     * вслепую там, где хватает вертящегося кружка на кнопке. Лимит выполнения
     * на всякий случай поднимаем: у донора p95 ответа модели — 34 секунды.
     *
     * @param  string  $ids  id реплик покупателя через запятую — та же ссылка,
     *                       что у кнопки «Ответить в базу знаний»
     */
    public function draftArticle(string $ids): void
    {
        $cluster = $this->clusterByQuestionIds($ids);

        if ($cluster === null) {
            // Отчёт пересчитался между показом и нажатием: сменилось окно,
            // приехали новые сигналы. Молчать нельзя — кнопка «не сработала».
            Notification::make()
                ->title('Группа изменилась, пока страница была открыта')
                ->body('Обновите экран и попробуйте ещё раз.')
                ->warning()
                ->send();

            return;
        }

        @set_time_limit(120);

        try {
            /*
             * Материалы для черновика ищем по ЦЕНТРУ группы, а не по одной
             * формулировке: центр уже посчитан кластеризацией, вектор
             * готов — значит поиск не стоит ни рубля и ни одного вызова
             * шлюза, в отличие от поиска по тексту.
             */
            $hits = app(KbVectorStore::class)->searchByVector(
                $cluster->centroid,
                (int) config('ai_support.knowledge_base.draft.top_k'),
            );

            $draft = app(KbArticleDrafter::class)->draft(
                array_map(static fn (KbGapQuestion $q): string => $q->text, $cluster->questions),
                $hits,
                // Ответы менеджеров, помеченные «в базу знаний»: у группы
                // они есть не всегда, но когда есть — статья пишется по ним,
                // а не по похожим кускам базы.
                $cluster->answers(),
            );
        } catch (Throwable $e) {
            Log::warning('kb draft failed', ['ids' => $ids, 'error' => $e->getMessage()]);

            Notification::make()
                ->title('Черновик собрать не удалось')
                ->body('Модель не ответила. Попробуйте ещё раз — или напишите статью сами, кнопка «Ответить в базу знаний» рядом.')
                ->danger()
                ->send();

            return;
        }

        if ($draft->isEmpty()) {
            Notification::make()
                ->title('Черновик получился пустым')
                ->body('Так бывает, когда в базе нет ничего близкого. Напишите статью сами — вопросы покупателей подставятся в форму.')
                ->warning()
                ->send();

            return;
        }

        $key = Str::random(24);

        Cache::put(
            self::draftCacheKey($key),
            $draft->toArray(),
            (int) config('ai_support.knowledge_base.draft.ttl'),
        );

        $this->redirect(KbArticleResource::getUrl('create', [
            'from' => implode(',', $cluster->questionMessageIds()),
            'draft' => $key,
        ]));
    }

    /** Ключ черновика в кэше — один вид на странице и в форме. */
    public static function draftCacheKey(string $key): string
    {
        return 'kb:draft:'.$key;
    }

    /**
     * Группа, чьи вопросы стоят в ссылке. Ищем в свежем отчёте, а не доверяем
     * пришедшим id: между показом страницы и нажатием кнопки могло смениться
     * окно наблюдения, и собирать черновик надо по тому, что видно сейчас.
     */
    private function clusterByQuestionIds(string $ids): ?KbGapCluster
    {
        $wanted = array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', $ids),
        )));

        if ($wanted === []) {
            return null;
        }

        foreach ($this->report()->clusters as $cluster) {
            if (array_intersect($wanted, $cluster->questionMessageIds()) !== []) {
                return $cluster;
            }
        }

        return null;
    }

    /**
     * «1 раз», «2 раза», «5 раз».
     *
     * Локаль приложения — `en`, и `trans_choice` склоняет по английским
     * правилам: уже на двойке он выдаёт «2 раз». Три формы ради одной
     * подписи дешевле написать здесь, чем заводить русскую локаль
     * со всеми её файлами ради одного экрана.
     */
    public function timesLabel(int $count): string
    {
        $tail = $count % 10;
        $hundred = $count % 100;

        if ($tail === 1 && $hundred !== 11) {
            return 'раз';
        }

        return $tail >= 2 && $tail <= 4 && ($hundred < 12 || $hundred > 14) ? 'раза' : 'раз';
    }

    /** @return array<string, string> */
    public function periodOptions(): array
    {
        return [
            '7' => 'За неделю',
            '30' => 'За месяц',
            '90' => 'За три месяца',
            '' => 'За всё время',
        ];
    }

    /** @return array<string, string> */
    public function signalOptions(): array
    {
        return ['' => 'Любой сигнал'] + KbGapAnalyzer::SIGNALS;
    }

    /**
     * Подписи и цвета сигналов в одном месте: их читают и в шапке отчёта,
     * и в каждой группе, и в списке несгруппированных.
     *
     * @return array<string, array{label: string, color: string}>
     */
    public function signalMeta(): array
    {
        return [
            KbGapQuestion::SIGNAL_RATED_DOWN => ['label' => 'Оценено плохо', 'color' => 'danger'],
            KbGapQuestion::SIGNAL_ESCALATED => ['label' => 'Позвал человека', 'color' => 'warning'],
            KbGapQuestion::SIGNAL_KB_MISS => ['label' => 'Не нашёл в базе', 'color' => 'gray'],
            KbGapQuestion::SIGNAL_OPERATOR_ANSWER => ['label' => 'Ответил менеджер', 'color' => 'success'],
        ];
    }
}

<?php

namespace App\Filament\Widgets;

use App\Models\AiUsageEntry;
use App\Services\Ai\GatewayBudget;
use App\Services\Chat\ChatAbuseGuard;
use Carbon\CarbonInterface;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Во сколько обходится бот — человеческим языком.
 *
 * Токены и стоимость лежат у каждого ответа, но лежат по одному: чтобы
 * понять «сколько мы тратим», их пришлось бы складывать глазами. Здесь
 * сложено, и намеренно в тех единицах, в которых думает владелец:
 * диалоги и рубли, а не 84 500 токенов.
 *
 * Рядом — остаток бюджета на ключе шлюза. Это единственная цифра здесь,
 * которая учитывает ВСЁ: песочницу, черновики статей, индексацию базы —
 * а пока ключ общий, ещё и чужие проекты. Наши счётчики считают только
 * ответы покупателям, и об этом сказано прямо, иначе расхождение с панелью
 * шлюза выглядит как ошибка.
 */
class AssistantSpendOverview extends StatsOverviewWidget
{
    /*
     * Виджет живёт над списком «Диалогов» и больше нигде: панель ищет
     * виджеты в app/Filament/Widgets сама, и без этого флага он всплыл бы
     * на любой будущей главной странице админки среди чужих ему чисел.
     */
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Расход на бота';

    protected int|string|array $columnSpan = 'full';

    /*
     * Опроса нет намеренно: цифры меняются от вопросов покупателей, а не
     * сами по себе, и обновляются при следующем открытии списка диалогов.
     * Живой счётчик денег на экране — это тик к серверу каждые несколько
     * секунд ради числа, на которое никто не смотрит непрерывно.
     */
    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $today = $this->totals(now()->startOfDay());
        $month = $this->totals(now()->startOfMonth());

        return [
            Stat::make('Сегодня', $this->plural((int) $today->conversations, 'диалог', 'диалога', 'диалогов'))
                ->description($this->money((float) $today->cost).' · '
                    .$this->plural((int) $today->answers, 'ответ бота', 'ответа бота', 'ответов бота'))
                ->descriptionIcon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('primary'),

            Stat::make('С начала месяца', $this->money((float) $month->cost))
                ->description($this->plural((int) $month->conversations, 'диалог', 'диалога', 'диалогов')
                    .' · в среднем '.$this->money($month->conversations > 0 ? $month->cost / $month->conversations : 0.0)
                    .' за диалог')
                ->descriptionIcon(Heroicon::OutlinedCalendarDays)
                ->color('gray'),

            $this->budgetStat(),
            $this->capStat(),
        ];
    }

    /**
     * Сумма и счётчики по ответам бота начиная с даты.
     *
     * Из расходной книги, а не из переписки: «Очистить переписку» удаляет
     * разговор насовсем, а `chat:purge` через полгода удаляет все, — и виджет
     * показывал бы расход тем меньший, чем дольше бот работает.
     */
    private function totals(CarbonInterface $since): object
    {
        return AiUsageEntry::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as answers')
            ->selectRaw('COUNT(DISTINCT conversation_ref) as conversations')
            ->selectRaw('COALESCE(SUM(cost_rub), 0) as cost')
            ->toBase()
            ->first() ?? (object) ['answers' => 0, 'conversations' => 0, 'cost' => 0.0];
    }

    private function budgetStat(): Stat
    {
        $budget = app(GatewayBudget::class)->snapshot();

        if ($budget === null) {
            /*
             * Две разные беды выглядят одинаково — бюджет не задан и шлюз
             * не ответил, — но обе требуют одного действия: пойти в панель
             * ключа. Поэтому одна формулировка, зато честная.
             */
            return Stat::make('Бюджет ключа', 'не задан')
                ->description('Расход ничем не ограничен. Лимит ставится в панели aitunnel, на самом ключе.')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('warning');
        }

        $percent = $budget['percent'];

        return Stat::make('Бюджет ключа', $percent === null
                ? $this->money($budget['used'])
                : 'израсходован на '.$percent.'%')
            ->description('осталось '.$this->money($budget['remaining']).' из '.$this->money($budget['initial'])
                .', '.$budget['interval'].'. Считает всё, что тратится с этого ключа, а не только ответы покупателям.')
            ->descriptionIcon(Heroicon::OutlinedBanknotes)
            ->color(match (true) {
                $percent === null => 'gray',
                $percent >= 80 => 'danger',
                $percent >= 50 => 'warning',
                default => 'success',
            });
    }

    /**
     * Дневной потолок намордника — единственное место, где его видно.
     *
     * Без этой плитки исчерпанный потолок выглядит как поломка бота: он
     * молчит, а все вопросы уходят менеджеру, и объяснения этому нет нигде.
     * Цифры не из расходной книги, а из счётчиков ChatAbuseGuard — считает
     * он их по московским суткам, как и сбрасывает.
     */
    private function capStat(): Stat
    {
        $spent = app(ChatAbuseGuard::class)->spentToday();

        $messagesCap = $spent['daily_messages'];
        $tokensCap = $spent['daily_tokens'];

        $exhausted = ($messagesCap > 0 && $spent['messages'] >= $messagesCap)
            || ($tokensCap > 0 && $spent['tokens'] >= $tokensCap);

        $share = $messagesCap > 0 ? $spent['messages'] / $messagesCap : 0.0;

        return Stat::make('Потолок на сегодня', $messagesCap > 0
                ? $spent['messages'].' из '.$messagesCap.' вопросов'
                : 'без потолка')
            ->description($exhausted
                ? 'Потолок выбран: бот молчит до полуночи, вопросы уходят менеджеру.'
                : $this->tokens($spent['tokens']).' из '
                    .($tokensCap > 0 ? $this->tokens($tokensCap) : 'неограниченного числа').' токенов')
            ->descriptionIcon($exhausted ? Heroicon::OutlinedHandRaised : Heroicon::OutlinedChartPie)
            ->color(match (true) {
                $exhausted => 'danger',
                $share >= 0.8 => 'warning',
                default => 'gray',
            });
    }

    /** Токены в единицах, которыми их читают, а не в цифре из девяти знаков. */
    private function tokens(int $count): string
    {
        return match (true) {
            $count >= 1_000_000 => number_format($count / 1_000_000, $count < 10_000_000 ? 1 : 0, ',', ' ').' млн',
            $count >= 10_000 => number_format($count / 1_000, 0, ',', ' ').' тыс.',
            default => (string) $count,
        };
    }

    private function money(float $rubles): string
    {
        return number_format($rubles, $rubles < 10 ? 2 : 0, ',', ' ').' ₽';
    }

    /**
     * «1 диалог», «2 диалога», «5 диалогов» — русские формы числительных,
     * которых нет в английской локали приложения (та же причина, что
     * у подписи на экране «Пробелы»).
     */
    private function plural(int $count, string $one, string $few, string $many): string
    {
        $tail = $count % 10;
        $hundred = $count % 100;

        $form = match (true) {
            $tail === 1 && $hundred !== 11 => $one,
            $tail >= 2 && $tail <= 4 && ($hundred < 12 || $hundred > 14) => $few,
            default => $many,
        };

        return $count.' '.$form;
    }
}

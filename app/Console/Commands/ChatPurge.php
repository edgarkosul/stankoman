<?php

namespace App\Console\Commands;

use App\Models\ChatConversation;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Сроки хранения переписки: обезличить, потом удалить.
 *
 * Две ступени, а не одна, потому что ценность у данных разная и кончается
 * она в разное время. Хэш адреса, user-agent и referer нужны для разбора
 * злоупотреблений — это дни. Сама переписка нужна магазину как след
 * разговора с клиентом — это месяцы.
 *
 * УДАЛЯЕТ НАСОВСЕМ. Мягкое удаление здесь было бы самообманом: строка
 * остаётся в таблице вместе со всем, что в ней написано, и «удалили»
 * превращается в «спрятали из админки». Сообщения уходят следом сами —
 * внешний ключ с cascadeOnDelete.
 *
 * ЧТО ОСТАЁТСЯ: расходная книга `ai_usage_entries`. В ней нет ни текста,
 * ни адреса, ни ссылки на разговор — только дата, модель, токены и рубли.
 * Сколько бот стоил в прошлом квартале, магазин обязан знать и после того,
 * как покупатель попросил забыть разговор.
 */
class ChatPurge extends Command
{
    protected $signature = 'chat:purge
        {--anonymize-days= : Через сколько дней обезличивать (по умолчанию из конфига)}
        {--purge-days= : Через сколько дней удалять насовсем}
        {--dry-run : Только показать, ничего не менять}';

    protected $description = 'Обезличить старые диалоги чата и удалить совсем старые';

    public function handle(): int
    {
        $anonymizeDays = (int) ($this->option('anonymize-days') ?? config('ai_support.chat.retention.anonymize_days'));
        $purgeDays = (int) ($this->option('purge-days') ?? config('ai_support.chat.retention.purge_days'));
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Удаление идёт ПЕРВЫМ: обезличив сначала, мы переписали бы строки,
         * которые через секунду сами же удалим.
         */
        $purged = $purgeDays > 0
            ? $this->purge(now()->subDays($purgeDays), $dryRun)
            : 0;

        $anonymized = $anonymizeDays > 0
            ? $this->anonymize(now()->subDays($anonymizeDays), $dryRun)
            : 0;

        $this->info(sprintf(
            '%sУдалено диалогов: %d (старше %d дн.), обезличено: %d (старше %d дн.).',
            $dryRun ? '[сухой прогон] ' : '',
            $purged,
            $purgeDays,
            $anonymized,
            $anonymizeDays,
        ));

        return self::SUCCESS;
    }

    /**
     * Насовсем, вместе с мягко удалёнными: строка, спрятанная из админки
     * полгода назад, — это те же данные, что и любая другая.
     */
    private function purge(CarbonInterface $before, bool $dryRun): int
    {
        if ($dryRun) {
            return $this->olderThan($before)->count();
        }

        // Порциями, а не одним DELETE: команда живёт в ночном планировщике
        // рядом с переиндексацией.
        $deleted = 0;

        while (true) {
            $chunk = $this->olderThan($before)->limit(500)->pluck('id');

            if ($chunk->isEmpty()) {
                break;
            }

            $removed = (int) ChatConversation::query()->withTrashed()->whereIn('id', $chunk)->forceDelete();

            // Ноль при непустой порции означает, что удалить не вышло, —
            // без этой ветки цикл крутился бы вечно.
            if ($removed === 0) {
                break;
            }

            $deleted += $removed;
        }

        return $deleted;
    }

    /**
     * Обезличивание: уходят хэш адреса, user-agent и referer. Переписка
     * остаётся — она и есть то, ради чего диалог хранят.
     */
    private function anonymize(CarbonInterface $before, bool $dryRun): int
    {
        $query = $this->olderThan($before)
            ->where(function (Builder $q): void {
                $q->whereNotNull('ip_hash')
                    ->orWhereNotNull('user_agent')
                    ->orWhereNotNull('referer_url');
            });

        if ($dryRun) {
            return $query->count();
        }

        return $query->update([
            'ip_hash' => null,
            'user_agent' => null,
            'referer_url' => null,
        ]);
    }

    /**
     * Диалоги старше даты — включая мягко удалённые.
     *
     * Возраст считается по ПОСЛЕДНЕМУ СООБЩЕНИЮ, а не по созданию: разговор,
     * начатый в марте и продолженный в августе, — свежий. У диалога без
     * сообщений (нажали «Позвать менеджера» и ушли) берётся дата создания.
     *
     * @return Builder<ChatConversation>
     */
    private function olderThan(CarbonInterface $before): Builder
    {
        return ChatConversation::query()
            ->withTrashed()
            ->whereRaw('COALESCE(last_message_at, created_at) < ?', [$before]);
    }
}

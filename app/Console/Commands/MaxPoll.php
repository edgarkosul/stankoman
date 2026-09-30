<?php

namespace App\Console\Commands;

use App\Services\Messengers\MaxClient;
use App\Services\Messengers\MaxUpdates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Забирать обновления бота MAX опросом. Только для дева.
 *
 * Webhook до localhost недостижим, поэтому подключить чат в деве можно
 * только так: запустить команду, нажать «Подключить MAX» в админке
 * и «Запустить» в чате.
 *
 * На проде не запускать. MAX отдаёт обновления одним способом из двух:
 * пока у бота есть webhook, опрос ничего не получит. Если же прод и дев
 * делят одного бота, `max:hook` на проде снимет опрос, а опрос в деве
 * будет перехватывать подключения с прода. Для дева нужен отдельный бот.
 */
class MaxPoll extends Command
{
    private const MARKER_KEY = 'max:updates:marker';

    protected $signature = 'max:poll {--once : один проход и выйти}';

    protected $description = 'Опрашивать бота MAX (только дев; на проде — webhook, см. max:hook)';

    public function handle(MaxClient $max, MaxUpdates $updates): int
    {
        if (! $max->configured()) {
            $this->error('Бот MAX не настроен: нужны MAX_BOT_TOKEN и MAX_BOT_LINK.');

            return self::FAILURE;
        }

        do {
            try {
                $batch = $max->updates(Cache::get(self::MARKER_KEY), 25);

                foreach ($batch['updates'] as $update) {
                    $this->line('MAX: '.($update['update_type'] ?? '?').' chat '.($update['chat_id'] ?? '?'));
                    $updates->handle($update);
                }

                if ($batch['marker'] !== null) {
                    Cache::forever(self::MARKER_KEY, $batch['marker']);
                }
            } catch (Throwable $e) {
                $this->warn($e->getMessage());
                sleep(3);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}

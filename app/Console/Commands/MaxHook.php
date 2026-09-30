<?php

namespace App\Console\Commands;

use App\Services\Messengers\MaxClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Указать MAX адрес webhook бота.
 *
 * Выполняется один раз при выкладке (и при смене домена). Адрес берётся из
 * маршрута `max.hook`, секрет из MAX_WEBHOOK_SECRET. В деве не нужна:
 * снаружи до localhost MAX не достучится, там работает `max:poll`.
 */
class MaxHook extends Command
{
    protected $signature = 'max:hook';

    protected $description = 'Зарегистрировать webhook бота MAX';

    public function handle(MaxClient $max): int
    {
        $secret = (string) config('services.max.webhook_secret');

        if (! $max->configured() || $secret === '') {
            $this->error('Нужны MAX_BOT_TOKEN, MAX_BOT_LINK и MAX_WEBHOOK_SECRET.');

            return self::FAILURE;
        }

        $url = route('max.hook');

        try {
            $max->subscribe($url, $secret);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('MAX: '.$url);

        return self::SUCCESS;
    }
}

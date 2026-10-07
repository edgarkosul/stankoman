<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiGatewayProbe as ProbeRow;
use App\Services\Ai\Support\GatewayAddressPin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Доступность шлюза — по каждому его адресу отдельно.
 *
 * Зачем не по имени хоста. На проде kratonshop 17.09.2026 «aitunnel часто недоступен»
 * оказалось так: шлюз за Cloudflare отдаёт два адреса, до одного из них
 * с прод-сервера не доходил SYN, второй отвечал за 20 мс, а curl выбирал
 * адрес сам и в ~70% случаев попадал в мёртвый. Проба по имени хоста
 * показала бы ровный флап и увела бы искать виноватого в поддержке
 * сервиса. Проба по адресам называет виновного сразу — и заодно даёт
 * клиенту живой адрес, который он подставит в CURLOPT_RESOLVE.
 *
 * Стоит ноль: ходим на бесключевой каталог моделей. Поэтому в расписании
 * её можно держать хоть каждые пять минут.
 *
 * Чего команда НЕ делает — не будит людей. Единственный её выход наружу
 * это лог: строка на смену состояния и ошибка, когда живых адресов
 * не осталось вовсе. Глазами состояние смотрят в `ai:kb-doctor`.
 */
class AiGatewayProbe extends Command
{
    protected $signature = 'ai:gateway-probe
        {--show : Показать журнал последних проб и выйти}';

    protected $description = 'Пробить каждый адрес шлюза и подставить клиенту живой';

    public function handle(GatewayAddressPin $pin): int
    {
        if ($this->option('show')) {
            return $this->show($pin);
        }

        if (($proxy = $pin->proxy()) !== null) {
            // Не ошибка, а другой стенд: так устроена dev-машина. Но молчать
            // нельзя — иначе пробы покажут ровные 100% на здоровье прокси
            // и выдадут их за связность со шлюзом.
            $this->warn('Трафик до шлюза идёт через прокси '.$proxy.' — пин и пробы по адресам бессмысленны.');

            return self::SUCCESS;
        }

        if (! $pin->enabled()) {
            $this->warn('Живой пин выключен (AI_GATEWAY_PIN=false) — пробовать нечего.');

            return self::SUCCESS;
        }

        $addresses = $pin->addresses();

        if ($addresses === []) {
            // Своё DNS не ответило. Пин при этом не трогаем: он ещё может
            // быть жив, а решать его судьбу по неудавшемуся резолву —
            // это менять рабочее на неизвестное.
            Log::error('Aitunnel probe: DNS не отдал адресов', ['host' => $pin->host()]);
            $this->error('DNS не отдал ни одного адреса для '.$pin->host());

            return self::FAILURE;
        }

        // Запасные — вслед за DNS: пинятся, только когда из DNS не ответил никто.
        $reserve = $pin->reserve($addresses);
        $current = $pin->current();
        $results = array_map(static fn (string $ip): array => $pin->probe($ip), [...$addresses, ...$reserve]);
        $chosen = $pin->choose($results, $current, $reserve);

        foreach ($results as $result) {
            ProbeRow::create([
                'ip' => $result['ip'],
                'ok' => $result['ok'],
                'http_status' => $result['http_status'],
                'latency_ms' => $result['latency_ms'],
                'error' => $result['error'],
                'pinned' => $result['ip'] === $chosen,
                'created_at' => now(),
            ]);
        }

        if ($chosen === null) {
            $pin->forget();
        } else {
            $pin->remember($chosen);
        }

        $this->report($results, $current, $chosen, $pin->host(), $reserve);
        $this->prune();

        if (! $this->option('quiet')) {
            $this->table(
                ['Адрес', 'Ответ', 'мс', 'Пин'],
                array_map(static fn (array $r): array => [
                    in_array($r['ip'], $reserve, true) ? $r['ip'].' (запас)' : $r['ip'],
                    $r['ok'] ? 'ок '.$r['http_status'] : ($r['error'] ?? 'нет'),
                    $r['latency_ms'],
                    $r['ip'] === $chosen ? '←' : '',
                ], $results),
            );
        }

        return $chosen === null ? self::FAILURE : self::SUCCESS;
    }

    /**
     * В лог — только события, а не каждый прогон: команда ходит раз
     * в несколько минут, и ровная строка «всё хорошо» утопила бы лог
     * за сутки.
     *
     * @param  list<array{ip: string, ok: bool, http_status: int|null, latency_ms: int, error: string|null}>  $results
     * @param  list<string>  $reserve
     */
    private function report(array $results, ?string $current, ?string $chosen, string $host, array $reserve): void
    {
        $dead = array_values(array_filter($results, static fn (array $r): bool => ! $r['ok']));

        if ($chosen === null) {
            Log::error('Aitunnel probe: живых адресов не осталось', [
                'host' => $host,
                'addresses' => array_column($results, 'ip'),
                'errors' => array_column($results, 'error'),
            ]);

            return;
        }

        if ($chosen !== $current) {
            Log::warning('Aitunnel probe: пин переехал', [
                'host' => $host,
                'from' => $current,
                'to' => $chosen,
                'reserve' => in_array($chosen, $reserve, true),
                'dead' => array_column($dead, 'ip'),
            ]);

            return;
        }

        if (in_array($chosen, $reserve, true)) {
            // Работаем, но на страховке: из DNS не отвечает ни один адрес.
            Log::warning('Aitunnel probe: пин на запасном адресе', [
                'host' => $host,
                'pin' => $chosen,
                'dead' => array_column($dead, 'ip'),
            ]);

            return;
        }

        if ($dead !== []) {
            // Деградация: работаем, но запаса нет. Отдельной строкой,
            // потому что это состояние «пин держится на одном адресе»,
            // и следующий его отказ будет полным.
            Log::warning('Aitunnel probe: часть адресов не отвечает', [
                'host' => $host,
                'dead' => array_column($dead, 'ip'),
                'alive' => count($results) - count($dead),
            ]);
        }
    }

    private function show(GatewayAddressPin $pin): int
    {
        $rows = ProbeRow::query()
            /*
             * Алиас НЕ `ok`: колонка с таким именем кастуется моделью
             * в boolean, и сумма «3» превратилась бы в «1». Ошибка тихая —
             * журнал показывал бы одну удачную пробу из трёх на здоровом
             * адресе, то есть ровно ту аварию, которую ищем.
             */
            ->selectRaw('ip, COUNT(*) n, SUM(ok) ok_count, ROUND(AVG(CASE WHEN ok THEN latency_ms END)) ms, MAX(created_at) last')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('ip')
            ->orderBy('ip')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('Проб за сутки нет. Команда в расписании?');

            return self::SUCCESS;
        }

        $this->table(
            ['Адрес', 'Проб', 'Успешно', 'Доля', 'Средний ответ, мс', 'Последняя'],
            $rows->map(fn (object $r): array => [
                $r->ip,
                $r->n,
                (int) $r->ok_count,
                $r->n > 0 ? round((int) $r->ok_count / $r->n * 100).'%' : '—',
                $r->ms ?? '—',
                $r->last,
            ])->all(),
        );

        $this->line('Сейчас пин: '.($pin->current() ?? 'нет, идём по DNS'));

        return self::SUCCESS;
    }

    /** Журнал не растёт бесконечно: он нужен на историю, а не навсегда. */
    private function prune(): void
    {
        ProbeRow::query()
            ->where('created_at', '<', now()->subDays((int) config('ai_support.gateway.pin.keep_days')))
            ->delete();
    }
}

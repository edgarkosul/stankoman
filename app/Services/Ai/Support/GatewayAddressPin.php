<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Живой пин адреса шлюза.
 *
 * Шлюз стоит за Cloudflare и отдаёт несколько A-записей. Часть из них
 * с конкретного сервера может быть недоступна — и это не отказ сервиса,
 * а фильтрация на пути: 17.09.2026 до 104.21.64.27 с прода kratonshop не доходил
 * SYN (ICMP при этом шёл, 21 мс), а соседний 172.67.175.12 отвечал
 * за 20 мс; с других сетей были живы оба.
 *
 * curl выбирает адрес сам и внутри одной попытки второй не перебирает,
 * поэтому такой расклад означает не «медленнее», а «каждый второй вызов
 * простоял таймаут и умер». Лечится подстановкой заведомо живого адреса
 * в CURLOPT_RESOLVE.
 *
 * ПОЧЕМУ НЕ /etc/hosts. Строка в hosts не знает ни про ротацию адресов
 * зоны, ни про то, что вчерашний живой адрес сегодня умер: в день
 * ротации она превращает частичный отказ в полный, причём молча. Здесь
 * выбор пересматривается каждым прогоном `ai:gateway-probe` по СВЕЖЕМУ
 * ответу DNS, а сам пин лежит в кэше с недолгим ttl. Умер планировщик —
 * пин протухает, и клиент возвращается к обычному DNS с ретраями.
 * Худший исход обязан быть «как было до пина».
 *
 * Пин оптимистичен: клиент выбрасывает его при первом же сорванном
 * соединении (см. AitunnelLlmClient::post), не дожидаясь следующей
 * пробы. Потерять хороший пин из-за случайного сбоя дёшево — это
 * возврат к прежнему поведению на несколько минут. Держаться за
 * мёртвый — дорого: тогда ретраи бьются в один и тот же адрес.
 *
 * ЗАПАСНЫЕ АДРЕСА. 07.10.2026 с прода bots, а следом и с нашего перестали
 * отвечать оба адреса из DNS, и пину стало не из чего выбирать: бот bots
 * лёг целиком. При этом
 * соседние адреса тех же сетей Cloudflare отдавали шлюз как ни в чём
 * не бывало: Cloudflare принимает зону на любом своём адресе и различает
 * её по SNI. Запасные адреса из настроек пробуются вместе с DNS-адресами,
 * но пинятся, только пока ни один адрес из DNS не отвечает. Так запас
 * остаётся страховкой и не подменяет зону, которая может переехать.
 */
final class GatewayAddressPin
{
    private const KEY = 'ai:gateway:pin:';

    /** @var list<string> */
    private readonly array $reserve;

    /**
     * @param  array<int, mixed>  $reserve  запасные адреса (IPv4), см. выше
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $healthPath,
        private readonly int $timeout,
        private readonly int $ttl,
        private readonly bool $enabled,
        array $reserve = [],
    ) {
        $this->reserve = self::ipv4List($reserve);
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->host() !== '' && $this->proxy() === null;
    }

    /**
     * Прокси, который перехватит трафик до шлюза, — если он есть.
     *
     * Пин через прокси не работает вообще: CURLOPT_RESOLVE подменяет
     * адрес КОНЕЧНОГО хоста, а curl в этом случае соединяется с прокси,
     * и подмена молча ни на что не влияет. Хуже того, и сами пробы тогда
     * меряют не путь до адресов шлюза, а здоровье прокси, — то есть
     * показывают ровные 100% ровно там, где связность и надо проверить.
     *
     * Так поймано на dev-машине 17.09.2026: в окружении стоит
     * https_proxy=http://127.0.0.1:8118, и пин на заведомо мёртвый
     * 192.0.2.1 не помешал запросу вернуть 200. На проде kratonshop прокси нет,
     * и разницу между двумя стендами лучше видеть явно, чем гадать,
     * почему пин «не приживается».
     *
     * Читаем окружение процесса, а не .env: переменные эти системные,
     * и libcurl смотрит именно в них.
     */
    public function proxy(): ?string
    {
        $host = $this->host();

        if ($host === '' || $this->bypassesProxy($host)) {
            return null;
        }

        $names = $this->scheme() === 'https'
            ? ['https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY']
            : ['http_proxy', 'HTTP_PROXY', 'all_proxy', 'ALL_PROXY'];

        foreach ($names as $name) {
            $value = getenv($name);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** Хост в списке исключений no_proxy — прокси его не тронет. */
    private function bypassesProxy(string $host): bool
    {
        foreach (['no_proxy', 'NO_PROXY'] as $name) {
            $value = getenv($name);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            foreach (explode(',', $value) as $entry) {
                $entry = trim($entry);

                if ($entry === '') {
                    continue;
                }

                if ($entry === '*' || strcasecmp($entry, $host) === 0) {
                    return true;
                }

                // Запись «.example.com» покрывает поддомены.
                if (str_starts_with($entry, '.') && str_ends_with(mb_strtolower($host), mb_strtolower($entry))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Имя хоста шлюза — по нему же ведётся журнал проб. */
    public function host(): string
    {
        return (string) (parse_url($this->baseUrl, PHP_URL_HOST) ?: '');
    }

    /** Выбранный живой адрес, если он есть и ещё не протух. */
    public function current(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $ip = Cache::get($this->cacheKey());

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * Строка для CURLOPT_RESOLVE: «хост:порт:адрес».
     */
    public function resolveEntry(): ?string
    {
        $ip = $this->current();

        return $ip === null ? null : $this->host().':'.$this->port().':'.$ip;
    }

    public function remember(string $ip): void
    {
        Cache::put($this->cacheKey(), $ip, $this->ttl);
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Свежий список адресов шлюза — из DNS, а не из /etc/hosts.
     *
     * Именно из DNS: если временный пин хостом ещё не убран, gethostbyname
     * вернул бы ровно его и проба перестала бы замечать, что зона переехала.
     *
     * Только A-записи. Там, где IPv6 до шлюза не работает вовсе (а на проде
     * это так — соединение отлетает за 1.5 мс), пробовать AAAA значит
     * заполнять журнал заведомым мусором.
     *
     * @return list<string>
     */
    public function addresses(): array
    {
        $records = @dns_get_record($this->host(), DNS_A);

        if (! is_array($records)) {
            return [];
        }

        return self::ipv4List(array_column($records, 'ip'));
    }

    /**
     * Запасные адреса, которых нет в DNS-ответе: их пробуют вслед
     * за адресами из DNS.
     *
     * @param  list<string>  $dns
     * @return list<string>
     */
    public function reserve(array $dns = []): array
    {
        return array_values(array_diff($this->reserve, $dns));
    }

    /**
     * Проба одного адреса: тот же хост, тот же TLS, но бесключевой
     * каталог моделей — ни авторизации, ни денег.
     *
     * @return array{ip: string, ok: bool, http_status: int|null, latency_ms: int, error: string|null}
     */
    public function probe(string $ip): array
    {
        $started = microtime(true);

        try {
            $response = Http::connectTimeout($this->timeout)
                ->timeout($this->timeout)
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$this->host().':'.$this->port().':'.$ip]]])
                ->get($this->healthUrl());
        } catch (ConnectionException $e) {
            return [
                'ip' => $ip,
                'ok' => false,
                'http_status' => null,
                'latency_ms' => $this->elapsed($started),
                'error' => $this->shorten($e->getMessage()),
            ];
        }

        return [
            'ip' => $ip,
            'ok' => $response->successful(),
            'http_status' => $response->status(),
            'latency_ms' => $this->elapsed($started),
            'error' => $response->successful() ? null : 'http '.$response->status(),
        ];
    }

    /**
     * Кого пинить по результатам проб.
     *
     * Здоровый — тот, что ответил. Из здоровых предпочитаем ТЕКУЩИЙ пин,
     * даже если сосед оказался на пару миллисекунд быстрее: переезд
     * ради шума в замере — это лишняя запись в журнале и лишний повод
     * подумать, что что-то сломалось. Меняем, только когда выбранный
     * адрес перестал отвечать.
     *
     * Запасные адреса участвуют, только когда не отвечает ни один адрес
     * из DNS. Ожил хоть один — пин возвращается на него, даже с живого
     * запасного: запасной адрес статичен и про переезд зоны не знает.
     *
     * @param  list<array{ip: string, ok: bool, http_status: int|null, latency_ms: int, error: string|null}>  $results
     * @param  list<string>  $reserve  запасные адреса среди $results (см. reserve())
     */
    public function choose(array $results, ?string $current, array $reserve = []): ?string
    {
        $healthy = array_values(array_filter($results, static fn (array $r): bool => $r['ok']));
        $fromDns = array_values(array_filter($healthy, static fn (array $r): bool => ! in_array($r['ip'], $reserve, true)));

        if ($fromDns !== []) {
            $healthy = $fromDns;
        }

        if ($healthy === []) {
            return null;
        }

        foreach ($healthy as $result) {
            if ($result['ip'] === $current) {
                return $current;
            }
        }

        usort($healthy, static fn (array $a, array $b): int => $a['latency_ms'] <=> $b['latency_ms']);

        return $healthy[0]['ip'];
    }

    private function healthUrl(): string
    {
        return $this->scheme().'://'.$this->host().'/'.ltrim($this->healthPath, '/');
    }

    private function scheme(): string
    {
        return (string) (parse_url($this->baseUrl, PHP_URL_SCHEME) ?: 'https');
    }

    private function port(): int
    {
        $port = parse_url($this->baseUrl, PHP_URL_PORT);

        return is_int($port) ? $port : ($this->scheme() === 'http' ? 80 : 443);
    }

    private function cacheKey(): string
    {
        return self::KEY.$this->host();
    }

    /**
     * Только A-записи, по той же причине, что и в addresses().
     *
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private static function ipv4List(array $values): array
    {
        $ips = array_filter(
            $values,
            static fn (mixed $ip): bool => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        );

        sort($ips);

        return array_values(array_unique($ips));
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /** Колонка `error` короткая: полный текст живёт в логе. */
    private function shorten(string $message): string
    {
        return mb_substr(trim($message), 0, 120);
    }
}

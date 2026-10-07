<?php

use App\Services\Ai\Support\GatewayAddressPin;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * Выбор адреса и его хранение — чистая логика поверх кэша. DNS и сеть
 * здесь не нужны: резолв проверяется живьём командой, а тут проверяется
 * то, что решает, куда пойдёт следующий запрос.
 */
uses(TestCase::class);

/*
 * Прокси в окружении гасит пин целиком и намеренно (см. GatewayAddressPin::proxy).
 * На dev-машине он стоит, поэтому тесты обязаны чистить окружение за собой,
 * иначе их результат зависит от того, из какого шелла их запустили.
 */
const PROXY_VARS = ['https_proxy', 'HTTPS_PROXY', 'http_proxy', 'HTTP_PROXY', 'all_proxy', 'ALL_PROXY'];

beforeEach(function (): void {
    Cache::flush();

    $this->savedProxyEnv = [];

    foreach (PROXY_VARS as $name) {
        $this->savedProxyEnv[$name] = getenv($name);
        putenv($name);
    }
});

afterEach(function (): void {
    foreach ($this->savedProxyEnv as $name => $value) {
        is_string($value) ? putenv("{$name}={$value}") : putenv($name);
    }
});

function pin(bool $enabled = true): GatewayAddressPin
{
    return new GatewayAddressPin(
        baseUrl: 'https://api.example.test/v1',
        healthPath: '/public/models',
        timeout: 3,
        ttl: 1800,
        enabled: $enabled,
    );
}

/** @return array{ip: string, ok: bool, http_status: int|null, latency_ms: int, error: string|null} */
function probeResult(string $ip, bool $ok, int $ms): array
{
    return [
        'ip' => $ip,
        'ok' => $ok,
        'http_status' => $ok ? 200 : null,
        'latency_ms' => $ms,
        'error' => $ok ? null : 'connect timeout',
    ];
}

it('подставляет пин в формате CURLOPT_RESOLVE', function (): void {
    $pin = pin();
    $pin->remember('172.67.175.12');

    expect($pin->resolveEntry())->toBe('api.example.test:443:172.67.175.12');
});

it('без пина не навязывает клиенту ничего', function (): void {
    expect(pin()->resolveEntry())->toBeNull();
});

it('выключенный пин молчит, даже если в кэше что-то лежит', function (): void {
    // Рубильник обязан быть сильнее кэша: иначе выключить пин на боевом
    // сервере можно было бы только вместе с чисткой кэша.
    $enabled = pin();
    $enabled->remember('172.67.175.12');

    expect(pin(enabled: false)->current())->toBeNull()
        ->and(pin(enabled: false)->resolveEntry())->toBeNull();
});

it('берёт самый быстрый живой адрес, когда пина ещё нет', function (): void {
    $chosen = pin()->choose([
        probeResult('104.21.64.27', ok: false, ms: 6000),
        probeResult('172.67.175.12', ok: true, ms: 120),
        probeResult('104.21.65.10', ok: true, ms: 40),
    ], current: null);

    expect($chosen)->toBe('104.21.65.10');
});

it('не переезжает с живого адреса ради пары миллисекунд', function (): void {
    // Переезд — это запись в журнале и повод подумать, что что-то
    // сломалось. Шум в замере таким поводом быть не должен.
    $chosen = pin()->choose([
        probeResult('172.67.175.12', ok: true, ms: 120),
        probeResult('104.21.65.10', ok: true, ms: 40),
    ], current: '172.67.175.12');

    expect($chosen)->toBe('172.67.175.12');
});

it('переезжает, когда выбранный адрес перестал отвечать', function (): void {
    $chosen = pin()->choose([
        probeResult('172.67.175.12', ok: false, ms: 6000),
        probeResult('104.21.65.10', ok: true, ms: 40),
    ], current: '172.67.175.12');

    expect($chosen)->toBe('104.21.65.10');
});

it('не выдумывает пин, когда не отвечает никто', function (): void {
    // Живых нет — возвращаемся к обычному DNS и ретраям. Приколотить
    // клиента к заведомо мёртвому адресу было бы хуже, чем жребий.
    $chosen = pin()->choose([
        probeResult('172.67.175.12', ok: false, ms: 6000),
        probeResult('104.21.65.10', ok: false, ms: 6000),
    ], current: '172.67.175.12');

    expect($chosen)->toBeNull();
});

it('берёт запасной адрес, когда из DNS не отвечает никто', function (): void {
    // Авария 07.10.2026: оба адреса из DNS мертвы, соседи из тех же сетей
    // Cloudflare живы. Без запаса пину было не из чего выбирать.
    $chosen = pin()->choose([
        probeResult('8.47.69.0', ok: false, ms: 6000),
        probeResult('8.6.112.0', ok: false, ms: 6000),
        probeResult('8.47.69.5', ok: true, ms: 40),
    ], current: null, reserve: ['8.47.69.5']);

    expect($chosen)->toBe('8.47.69.5');
});

it('предпочитает адрес из DNS запасному, даже более быстрому', function (): void {
    $chosen = pin()->choose([
        probeResult('8.47.69.0', ok: true, ms: 90),
        probeResult('8.47.69.5', ok: true, ms: 40),
    ], current: null, reserve: ['8.47.69.5']);

    expect($chosen)->toBe('8.47.69.0');
});

it('возвращается с запасного адреса, как только ожил адрес из DNS', function (): void {
    // Запасной статичен и про переезд зоны не знает: держаться за него,
    // когда DNS снова в порядке, незачем.
    $chosen = pin()->choose([
        probeResult('8.47.69.0', ok: true, ms: 90),
        probeResult('8.47.69.5', ok: true, ms: 40),
    ], current: '8.47.69.5', reserve: ['8.47.69.5']);

    expect($chosen)->toBe('8.47.69.0');
});

it('держится за запасной адрес, пока DNS не ожил', function (): void {
    $chosen = pin()->choose([
        probeResult('8.47.69.0', ok: false, ms: 6000),
        probeResult('8.47.69.5', ok: true, ms: 90),
        probeResult('8.6.112.5', ok: true, ms: 40),
    ], current: '8.47.69.5', reserve: ['8.47.69.5', '8.6.112.5']);

    expect($chosen)->toBe('8.47.69.5');
});

it('не пробует запасной адрес дважды, если DNS отдал его сам', function (): void {
    $pin = new GatewayAddressPin(
        baseUrl: 'https://api.example.test/v1',
        healthPath: '/public/models',
        timeout: 3,
        ttl: 1800,
        enabled: true,
        reserve: ['8.6.112.5', ' ', 'not-an-ip', '2a06:98c1:3122:8000::', '8.47.69.5', '8.47.69.5'],
    );

    // Мусор и IPv6 отброшены, дубль схлопнут, адрес из DNS — уже не запас.
    expect($pin->reserve())->toBe(['8.47.69.5', '8.6.112.5'])
        ->and($pin->reserve(['8.47.69.0', '8.47.69.5']))->toBe(['8.6.112.5']);
});

it('считает пробу неудачной, когда шлюз ответил ошибкой', function (): void {
    Http::fake(['*' => Http::response('nope', 502)]);

    expect(pin()->probe('172.67.175.12'))
        ->toMatchArray(['ip' => '172.67.175.12', 'ok' => false, 'http_status' => 502, 'error' => 'http 502']);
});

it('гаснет, когда трафик идёт через прокси', function (): void {
    // CURLOPT_RESOLVE подменяет адрес конечного хоста, а через прокси curl
    // соединяется с прокси — подмена не делает ничего. Хуже: пробы тогда
    // меряют здоровье прокси и выдают его за связность со шлюзом.
    putenv('https_proxy=http://127.0.0.1:8118');

    $pin = pin();
    $pin->remember('172.67.175.12');

    expect($pin->proxy())->toBe('http://127.0.0.1:8118')
        ->and($pin->enabled())->toBeFalse()
        ->and($pin->resolveEntry())->toBeNull();
});

it('не считает прокси помехой, когда хост в no_proxy', function (): void {
    putenv('https_proxy=http://127.0.0.1:8118');
    putenv('no_proxy=localhost,.example.test');

    try {
        expect(pin()->proxy())->toBeNull()
            ->and(pin()->enabled())->toBeTrue();
    } finally {
        putenv('no_proxy');
    }
});

it('переживает обрыв связи и записывает причину', function (): void {
    Http::fake(function (): never {
        throw new ConnectionException('cURL error 28: Connection timed out');
    });

    $result = pin()->probe('104.21.64.27');

    expect($result['ok'])->toBeFalse()
        ->and($result['http_status'])->toBeNull()
        ->and($result['error'])->toContain('cURL error 28');
});

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Event;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Сторожа живут здесь, а не в beforeEach из tests/Pest.php: голый
     * beforeEach() там Pest применяет только к самому Pest.php, и с апреля
     * 2026 эти проверки не выполнялись ни разу. А beforeEach и в рабочем
     * виде идёт после setUp(), то есть после migrate:fresh от RefreshDatabase.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->forbidLiveRedisQueues();
    }

    /**
     * Приложение уже собрано, а migrate:fresh от RefreshDatabase ещё впереди —
     * единственная точка, где проверка базы может что-то спасти. Хук трейта
     * beforeRefreshingDatabase() для этого не годится: трейт в классе теста
     * перекрывает метод родителя своей пустой версией.
     */
    protected function setUpTraits()
    {
        $this->ensureSafeTestingDatabase();

        return parent::setUpTraits();
    }

    protected function ensureSafeTestingDatabase(): void
    {
        if (! $this->app->environment('testing')) {
            throw new RuntimeException('Tests must run in the testing environment.');
        }

        $defaultConnection = (string) config('database.default');
        $connection = config("database.connections.{$defaultConnection}", []);
        $driver = $connection['driver'] ?? null;
        $database = $connection['database'] ?? null;

        $isSafeSqliteConnection = $driver === 'sqlite';
        $isSafeDedicatedDatabase = is_string($database) && str_contains($database, '_test');

        if (! $isSafeSqliteConnection && ! $isSafeDedicatedDatabase) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against the [%s] connection with database [%s].',
                $defaultConnection,
                is_scalar($database) ? (string) $database : 'unknown',
            ));
        }
    }

    /**
     * Задача на соединении с драйвером redis из теста уходит в живую очередь дева.
     *
     * QUEUE_CONNECTION=sync её не останавливает: соединение назначает сама задача
     * (бот — `redis-assistant`). Так каждый полный прогон оставлял в очереди
     * ассистента по десятку переиндексаций страниц, сохранённых тестами, и воркер
     * дева потом гонял их в шлюз. Queue::fake() это событие не поднимает —
     * значит, падает только тест, который его забыл.
     */
    protected function forbidLiveRedisQueues(): void
    {
        Event::listen(JobQueueing::class, function (JobQueueing $event): void {
            if (config("queue.connections.{$event->connectionName}.driver") !== 'redis') {
                return;
            }

            throw new RuntimeException(sprintf(
                'Задача [%s] ушла бы в живой Redis дева (соединение %s) — в тесте нужен Queue::fake().',
                is_object($event->job) ? $event->job::class : (string) $event->job,
                $event->connectionName,
            ));
        });
    }
}

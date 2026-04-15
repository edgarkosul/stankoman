<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

beforeEach(function (): void {
    ensureSafeTestingDatabase();
    ensureBackupTablesExist();
});

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function ensureSafeTestingDatabase(): void
{
    if (! app()->environment('testing')) {
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

function ensureBackupTablesExist(): void
{
    if (! Schema::hasTable('backup_settings')) {
        Schema::create('backup_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('endpoint')->nullable();
            $table->string('bucket')->nullable();
            $table->string('prefix')->nullable();
            $table->string('repository_prefix')->nullable();
            $table->text('access_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->text('restic_repository')->nullable();
            $table->text('restic_password')->nullable();
            $table->json('retention')->nullable();
            $table->json('schedule')->nullable();
            $table->json('paths')->nullable();
            $table->string('project_root')->nullable();
            $table->string('baseline_snapshot_id')->nullable();
            $table->timestamp('baseline_created_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('backup_runs')) {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }
}

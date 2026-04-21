<?php

use App\Models\Unit;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('normalizes whitespace case and punctuation', function () {
    $value = "  УШМ\t— 125мм\n«Bosch»  ";

    expect(NameNormalizer::normalize($value))
        ->toBe('ушм - 125мм "bosch"');
});

it('strips a trailing known unit from characteristic names', function () {
    Unit::query()->create([
        'name' => 'Миллиметр',
        'symbol' => 'мм',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.001,
        'si_offset' => 0,
    ]);

    expect(NameNormalizer::normalize('Размер смотрового окна, мм'))
        ->toBe('размер смотрового окна');
});

it('does not strip a trailing token if it is not a known unit', function () {
    Unit::query()->create([
        'name' => 'Миллиметр',
        'symbol' => 'мм',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.001,
        'si_offset' => 0,
    ]);

    expect(NameNormalizer::normalize('Диапазон затемнения, DIN'))
        ->toBe('диапазон затемнения, din');
});

it('memoizes unit token lookups within a request', function () {
    Unit::query()->create([
        'name' => 'Миллиметр',
        'symbol' => 'мм',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.001,
        'si_offset' => 0,
    ]);

    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(NameNormalizer::normalize('Размер смотрового окна, мм'))
        ->toBe('размер смотрового окна')
        ->and(NameNormalizer::normalize('Глубина окна, мм'))
        ->toBe('глубина окна');

    $queries = collect($connection->getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'from "units"') || str_contains($sql, 'from `units`'));

    expect($queries->count())
        ->toBe(1);
});

it('flushes memoized unit tokens when units change', function () {
    expect(NameNormalizer::normalize('Диапазон затемнения, DIN'))
        ->toBe('диапазон затемнения, din');

    Unit::query()->create([
        'name' => 'DIN',
        'symbol' => 'DIN',
        'dimension' => 'dimensionless',
        'base_symbol' => '1',
        'si_factor' => 1,
        'si_offset' => 0,
    ]);

    expect(NameNormalizer::normalize('Диапазон затемнения, DIN'))
        ->toBe('диапазон затемнения');
});

it('returns null for empty values after normalization', function () {
    expect(NameNormalizer::normalize(null))->toBeNull();
    expect(NameNormalizer::normalize(" \t\n "))->toBeNull();
});

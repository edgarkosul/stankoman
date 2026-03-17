<?php

use App\Models\Unit;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('returns null for empty values after normalization', function () {
    expect(NameNormalizer::normalize(null))->toBeNull();
    expect(NameNormalizer::normalize(" \t\n "))->toBeNull();
});

<?php

use App\Support\NameNormalizer;

it('normalizes whitespace case and punctuation', function () {
    $value = "  УШМ\t— 125мм\n«Bosch»  ";

    expect(NameNormalizer::normalize($value))
        ->toBe('ушм - 125мм "bosch"');
});

it('returns null for empty values after normalization', function () {
    expect(NameNormalizer::normalize(null))->toBeNull();
    expect(NameNormalizer::normalize(" \t\n "))->toBeNull();
});

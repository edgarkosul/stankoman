<?php

use App\Support\Metalmaster\MetalmasterBucketCatalog;
use Tests\TestCase;

uses(TestCase::class);

test('metalmaster bucket catalog reads options and labels from buckets file', function () {
    $bucketsPath = storage_path('app/parser/metalmaster-buckets.json');
    $originalBuckets = is_file($bucketsPath) ? file_get_contents($bucketsPath) : null;

    if (! is_dir(dirname($bucketsPath))) {
        mkdir(dirname($bucketsPath), 0777, true);
    }

    file_put_contents($bucketsPath, json_encode([
        'buckets' => [
            [
                'bucket' => 'promyshlennye',
                'category_url' => 'https://metalmaster.ru/promyshlennye/',
                'products_count' => 20,
            ],
            [
                'bucket' => 'instrument',
                'category_url' => 'https://metalmaster.ru/instrument/',
                'products_count' => 5,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    try {
        $catalog = app(MetalmasterBucketCatalog::class);

        expect($catalog->options())->toBe([
            'promyshlennye' => 'promyshlennye (20)',
            'instrument' => 'instrument (5)',
        ]);
        expect($catalog->options(search: 'instr'))->toBe([
            'instrument' => 'instrument (5)',
        ]);
        expect($catalog->label('promyshlennye'))->toBe('promyshlennye (20)');
        expect($catalog->hasBucket('instrument'))->toBeTrue();
        expect($catalog->hasBucket('unknown'))->toBeFalse();
    } finally {
        if (is_string($originalBuckets)) {
            file_put_contents($bucketsPath, $originalBuckets);
        } elseif (is_file($bucketsPath)) {
            @unlink($bucketsPath);
        }
    }
});

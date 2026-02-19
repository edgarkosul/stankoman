<?php

use App\Models\Category;
use Database\Seeders\MetalmasterCatalogCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('imports category tree from metalmaster parser files and skips product pages', function (): void {
    $treePath = storage_path('app/parser/metalmaster-catalog-tree.json');
    $bucketsPath = storage_path('app/parser/metalmaster-buckets.json');

    $originalTree = is_file($treePath) ? file_get_contents($treePath) : null;
    $originalBuckets = is_file($bucketsPath) ? file_get_contents($bucketsPath) : null;

    if (! is_dir(dirname($treePath))) {
        mkdir(dirname($treePath), 0777, true);
    }

    $treeFixture = [
        'tree' => [
            [
                'title' => 'Станки для гибки',
                'url' => 'https://metalmaster.ru/stanki_gibki_met/',
                'path' => '/stanki_gibki_met',
                'children' => [
                    [
                        'title' => 'Листогибочные прессы',
                        'url' => 'https://metalmaster.ru/listogibochnye_pressy/',
                        'path' => '/listogibochnye_pressy',
                        'children' => [
                            [
                                'title' => 'HPJ-2540',
                                'url' => 'https://metalmaster.ru/listogibochnye_pressy/hpj-2540/',
                                'path' => '/listogibochnye_pressy/hpj-2540',
                                'children' => [],
                            ],
                        ],
                    ],
                    [
                        'title' => 'Листогибы',
                        'url' => 'https://metalmaster.ru/listogiby/',
                        'path' => '/listogiby',
                        'children' => [
                            [
                                'title' => 'LBM-250',
                                'url' => 'https://metalmaster.ru/listogiby/lbm-250/',
                                'path' => '/listogiby/lbm-250',
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Штабелеры',
                'url' => 'https://metalmaster.ru/shtabelery/',
                'path' => '/shtabelery',
                'children' => [
                    [
                        'title' => 'MHS-1016',
                        'url' => 'https://metalmaster.ru/shtabelery/mhs-1016/',
                        'path' => '/shtabelery/mhs-1016',
                        'children' => [],
                    ],
                ],
            ],
        ],
    ];

    $bucketsFixture = [
        [
            'bucket' => 'listogibochnye_pressy',
            'category_url' => 'https://metalmaster.ru/listogibochnye_pressy/',
            'products_count' => 1,
            'product_urls' => [
                'https://metalmaster.ru/listogibochnye_pressy/hpj-2540/',
            ],
        ],
        [
            'bucket' => 'listogiby',
            'category_url' => 'https://metalmaster.ru/listogiby/',
            'products_count' => 1,
            'product_urls' => [
                'https://metalmaster.ru/listogiby/lbm-250/',
            ],
        ],
        [
            'bucket' => 'shtabelery',
            'category_url' => 'https://metalmaster.ru/shtabelery/',
            'products_count' => 1,
            'product_urls' => [
                'https://metalmaster.ru/shtabelery/mhs-1016/',
            ],
        ],
    ];

    file_put_contents($treePath, json_encode($treeFixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    file_put_contents($bucketsPath, json_encode($bucketsFixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    try {
        Category::query()->create([
            'name' => 'Legacy',
            'slug' => 'legacy',
            'parent_id' => Category::defaultParentKey(),
            'order' => 1,
            'is_active' => true,
        ]);

        $this->seed(MetalmasterCatalogCategorySeeder::class);

        expect(Category::query()->where('slug', 'legacy')->exists())->toBeFalse();
        expect(Category::query()->count())->toBe(4);

        $root = Category::query()->where('slug', 'stanki_gibki_met')->first();
        $presses = Category::query()->where('slug', 'listogibochnye_pressy')->first();
        $listogiby = Category::query()->where('slug', 'listogiby')->first();
        $stackers = Category::query()->where('slug', 'shtabelery')->first();

        expect($root)->not->toBeNull();
        expect($presses)->not->toBeNull();
        expect($listogiby)->not->toBeNull();
        expect($stackers)->not->toBeNull();
        expect($presses?->parent_id)->toBe($root?->getKey());
        expect($listogiby?->parent_id)->toBe($root?->getKey());
        expect($stackers?->parent_id)->toBe(Category::defaultParentKey());

        expect(Category::query()->where('slug', 'hpj-2540')->exists())->toBeFalse();
        expect(Category::query()->where('slug', 'lbm-250')->exists())->toBeFalse();
        expect(Category::query()->where('slug', 'mhs-1016')->exists())->toBeFalse();
    } finally {
        if (is_string($originalTree)) {
            file_put_contents($treePath, $originalTree);
        } else {
            @unlink($treePath);
        }

        if (is_string($originalBuckets)) {
            file_put_contents($bucketsPath, $originalBuckets);
        } else {
            @unlink($bucketsPath);
        }
    }
});

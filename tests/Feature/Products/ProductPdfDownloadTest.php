<?php

use App\Models\Product;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

it('shows product pdf download link on product page', function (): void {
    $product = Product::query()->create([
        'name' => 'Тестовый товар для скачивания PDF',
        'slug' => 'test-product-pdf-download-link',
        'is_active' => true,
        'price_amount' => 125_000,
    ]);

    $downloadUrl = route('product.print', ['product' => $product, 'dl' => 1], false);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee($downloadUrl, false)
        ->assertSee('Скачать PDF', false);
});

it('stacks product sku below actions below the xs breakpoint', function (): void {
    $product = Product::query()->create([
        'name' => 'Тестовый товар с мобильным артикулом',
        'slug' => 'test-product-mobile-sku-layout',
        'sku' => 'MOBILE-SKU-1',
        'is_active' => true,
        'price_amount' => 125_000,
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee('flex flex-col items-start gap-3 xs:flex-row xs:items-center xs:justify-between', false)
        ->assertSee('Артикул</span>: MOBILE-SKU-1', false);
});

it('shows product share action with native share and clipboard fallback', function (): void {
    $product = Product::query()->create([
        'name' => 'Тестовый товар для шаринга',
        'slug' => 'test-product-share-action',
        'is_active' => true,
        'price_amount' => 125_000,
    ]);

    $shareUrl = route('product.show', ['product' => $product]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee('js-share-product', false)
        ->assertSee('aria-label="Поделиться"', false)
        ->assertSee('data-title="Тестовый товар для шаринга"', false)
        ->assertSee('data-text="Тестовый товар для шаринга"', false)
        ->assertSee('data-url="'.$shareUrl.'"', false)
        ->assertSee('navigator.share', false)
        ->assertSee('navigator.clipboard.writeText', false)
        ->assertSee('Ссылка скопирована', false)
        ->assertSeeInOrder(['Скачать PDF', 'Поделиться'], false);
});

it('downloads product pdf when dl query parameter is set', function (): void {
    $product = Product::query()->create([
        'name' => 'Тестовый товар PDF download',
        'slug' => 'test-product-pdf-download-response',
        'is_active' => true,
        'price_amount' => 90_000,
    ]);

    $response = $this->get(route('product.print', ['product' => $product, 'dl' => 1]));

    $response
        ->assertOk()
        ->assertDownload()
        ->assertHeader('content-type', 'application/pdf');

    $pdf = $response->getContent();

    expect($pdf)
        ->toStartWith('%PDF-')
        ->toContain('/FontName /RobotoCondensed-Regular')
        ->toContain('/FontName /RobotoCondensed-Bold')
        ->not->toContain('/BaseFont /inter_');
});

it('renders configured company contacts and bank details in product pdf offer view', function (): void {
    config()->set('company.site_url', 'https://settings.example.com');
    config()->set('company.site_host', 'docs.settings.example.com');
    config()->set('company.phone', '+7 (999) 123-45-67');
    config()->set('company.legal_addr', 'г. Краснодар, ул. Тестовая, 10');
    config()->set('company.bank.name', 'Тестовый банк');
    config()->set('company.bank.bik', '012345678');
    config()->set('company.bank.rs', '40802810999999999999');
    config()->set('company.bank.ks', '30101810999999999999');

    $product = Product::query()->create([
        'name' => 'Тестовый товар PDF offer view',
        'slug' => 'test-product-pdf-offer-view',
        'is_active' => true,
        'price_amount' => 90_000,
    ]);

    $html = view('pages.product.pdf.offer', [
        'product' => $product,
        'cover' => null,
        'sku' => $product->id,
        'price' => '90 000 ₽',
        'attributes' => [],
        'descriptionHtml' => '<p>Описание</p>',
    ])->render();

    expect($html)
        ->toContain('font-family: "RobotoCondensed"')
        ->toContain('font-family: RobotoCondensed, sans-serif')
        ->not->toContain('font-family: "Inter"')
        ->not->toContain('font-family: Inter, sans-serif')
        ->toContain('КОНТАКТЫ И РЕКВИЗИТЫ')
        ->toContain('https://settings.example.com')
        ->toContain('docs.settings.example.com')
        ->toContain('+7 (999) 123-45-67')
        ->toContain('г. Краснодар, ул. Тестовая, 10')
        ->toContain('Тестовый банк')
        ->toContain('012345678')
        ->toContain('40802810999999999999')
        ->toContain('30101810999999999999');
});

it('keeps the pdf offer out of search results', function (): void {
    $product = Product::query()->create([
        'name' => 'Тестовый товар PDF noindex',
        'slug' => 'test-product-pdf-noindex',
        'is_active' => true,
        'price_amount' => 90_000,
    ]);

    $this->get(route('product.print', ['product' => $product]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('marks both pdf links on the product page as nofollow', function (): void {
    $product = Product::query()->create([
        'name' => 'Тестовый товар PDF nofollow',
        'slug' => 'test-product-pdf-nofollow',
        'is_active' => true,
        'price_amount' => 90_000,
    ]);

    $html = $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->getContent();

    $printUrl = route('product.print', ['product' => $product]);
    $downloadUrl = route('product.print', ['product' => $product, 'dl' => 1]);

    foreach ([$printUrl, $downloadUrl] as $url) {
        $anchor = preg_match('~<a\b[^>]*href="'.preg_quote($url, '~').'"[^>]*>~', $html, $m) === 1
            ? $m[0]
            : '';

        expect($anchor)->toContain('rel="nofollow"');
    }
});

it('throttles the pdf offer route', function (): void {
    $product = Product::query()->create([
        'name' => 'Тестовый товар PDF throttle',
        'slug' => 'test-product-pdf-throttle',
        'is_active' => true,
        'price_amount' => 90_000,
    ]);

    $url = route('product.print', ['product' => $product]);

    for ($i = 0; $i < 12; $i++) {
        $this->get($url)->assertOk();
    }

    $this->get($url)->assertStatus(429);
});

it('serves a second request for the same offer from disk', function (): void {
    Storage::fake('local');

    $product = Product::query()->create([
        'name' => 'Тестовый товар PDF кэш',
        'slug' => 'test-product-pdf-cache',
        'is_active' => true,
        'price_amount' => 90_000,
    ]);

    $url = route('product.print', ['product' => $product]);

    $first = $this->get($url)->assertOk()->getContent();

    $directory = Storage::disk('local')->path('pdf-offers');
    $files = File::glob($directory.'/product-'.$product->id.'.*.pdf');

    expect($files)->toHaveCount(1)
        ->and(File::get($files[0]))->toBe($first);

    // Подменяем сложенный файл: если второй ответ придёт с подменой,
    // значит оферту не пересобирали, а взяли с диска.
    File::put($files[0], '%PDF-1.4 из кэша');

    expect($this->get($url)->assertOk()->getContent())->toBe('%PDF-1.4 из кэша');
});

it('rebuilds the offer when the product changes and keeps one file per product', function (): void {
    Storage::fake('local');

    $product = Product::query()->create([
        'name' => 'Тестовый товар PDF инвалидация',
        'slug' => 'test-product-pdf-invalidation',
        'is_active' => true,
        'price_amount' => 90_000,
    ]);

    $url = route('product.print', ['product' => $product]);
    $directory = Storage::disk('local')->path('pdf-offers');

    $this->get($url)->assertOk();

    $before = File::glob($directory.'/product-'.$product->id.'.*.pdf');
    expect($before)->toHaveCount(1);

    // updated_at хранится с точностью до секунды: без сдвига во времени
    // правка попадает в ту же секунду, что и создание, и ключ не меняется.
    $this->travel(1)->minutes();

    $product->forceFill(['price_amount' => 111_000])->save();

    $this->get($url)->assertOk();

    $after = File::glob($directory.'/product-'.$product->id.'.*.pdf');

    expect($after)->toHaveCount(1)
        ->and($after[0])->not->toBe($before[0])
        ->and(File::exists($before[0]))->toBeFalse();
});

it('does not leave temporary files behind', function (): void {
    Storage::fake('local');

    $product = Product::query()->create([
        'name' => 'Тестовый товар PDF временные файлы',
        'slug' => 'test-product-pdf-tmp',
        'is_active' => true,
        'price_amount' => 90_000,
    ]);

    $this->get(route('product.print', ['product' => $product]))->assertOk();

    expect(File::glob(Storage::disk('local')->path('pdf-offers').'/*.tmp'))->toBeEmpty();
});

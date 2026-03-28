<?php

use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\HeroSliderBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageGalleryBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\PdfLinkBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RawHtmlBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RutubeVideoBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\SellerRequisitesBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YoutubeVideoBlock;
use App\Models\Slider;
use App\Support\Filament\PdfLinkBlockConfigNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('renders image custom block using public storage url', function () {
    config(['filesystems.disks.public.url' => '/storage']);

    $html = ImageBlock::toHtml([
        'file' => 'pics/example.jpg',
        'alt' => 'Example',
    ], []);

    expect($html)->toContain('/storage/pics/example.jpg')
        ->and($html)->toContain('alt="Example"');
});

it('renders image gallery custom block with multiple images', function () {
    config(['filesystems.disks.public.url' => '/storage']);

    $html = ImageGalleryBlock::toHtml([
        'images' => [
            ['file' => 'pics/one.jpg', 'alt' => 'One'],
            ['file' => 'pics/two.jpg', 'alt' => 'Two'],
        ],
        'width' => 640,
        'alignment' => 'left',
    ], []);

    expect($html)->toContain('data-image-gallery')
        ->and($html)->toContain('data-image-gallery-main')
        ->and($html)->toContain('data-image-gallery-thumbs')
        ->and($html)->toContain('image-gallery--left')
        ->and($html)->toContain('style="max-width: min(100%, 640px);"')
        ->and($html)->toContain('/storage/pics/one.jpg')
        ->and($html)->toContain('/storage/pics/two.jpg');
});

it('renders image gallery custom block with photoswipe dimensions when files exist', function () {
    Storage::fake('public');
    config(['filesystems.disks.public.url' => '/storage']);

    $first = UploadedFile::fake()->image('one.jpg', 1200, 800);
    $second = UploadedFile::fake()->image('two.jpg', 640, 480);

    Storage::disk('public')->putFileAs('pics', $first, 'one.jpg');
    Storage::disk('public')->putFileAs('pics', $second, 'two.jpg');

    $html = ImageGalleryBlock::toHtml([
        'images' => [
            ['file' => 'pics/one.jpg', 'alt' => 'One'],
            ['file' => 'pics/two.jpg', 'alt' => 'Two'],
        ],
    ], []);

    expect($html)->toContain('data-pswp-width="1200"')
        ->and($html)->toContain('data-pswp-height="800"')
        ->and($html)->toContain('data-pswp-width="640"')
        ->and($html)->toContain('data-pswp-height="480"');
});

it('renders hero slider custom block with image dimensions and prioritized first slide', function () {
    Storage::fake('public');
    config(['filesystems.disks.public.url' => '/storage']);

    $first = UploadedFile::fake()->image('hero-first.jpg', 1600, 700);
    $second = UploadedFile::fake()->image('hero-second.jpg', 1200, 675);

    Storage::disk('public')->putFileAs('pics', $first, 'hero-first.jpg');
    Storage::disk('public')->putFileAs('pics', $second, 'hero-second.jpg');

    $slider = Slider::query()->create([
        'name' => 'Homepage hero',
        'slides' => [
            ['image' => 'pics/hero-first.jpg', 'alt' => 'First', 'url' => '/promo'],
            ['image' => 'pics/hero-second.jpg', 'alt' => 'Second'],
        ],
    ]);

    $html = HeroSliderBlock::toHtml([
        'slider_id' => $slider->id,
    ], []);

    expect($html)->toContain('data-hero-slider')
        ->and($html)->toContain('href="/promo"')
        ->and($html)->toContain('alt="First"')
        ->and($html)->toContain('width="1600"')
        ->and($html)->toContain('height="700"')
        ->and($html)->toContain('loading="eager"')
        ->and($html)->toContain('fetchpriority="high"')
        ->and($html)->toContain('loading="lazy"');
});

it('renders raw html custom block without modification', function () {
    $html = RawHtmlBlock::toHtml([
        'html' => '<div data-test="raw">OK</div>',
    ], []);

    expect($html)->toBe('<div data-test="raw">OK</div>');
});

it('renders seller requisites custom block from company config', function () {
    config()->set('company.legal_name', 'ООО Тестовая компания');
    config()->set('company.inn', '231102927496');
    config()->set('company.ogrn', '1234567890123');
    config()->set('company.ogrnip', '');
    config()->set('company.legal_addr', 'г. Краснодар, ул. Тестовая, 10');
    config()->set('company.correspondence_addr', 'г. Краснодар, а/я 100');
    config()->set('company.public_email', 'public@example.com');
    config()->set('company.phone', '+7 (999) 123-45-67');

    $html = SellerRequisitesBlock::toHtml([], []);

    expect($html)->toContain('Продавец / Администрация сайта')
        ->and($html)->toContain('ООО Тестовая компания')
        ->and($html)->toContain('ИНН: 231102927496')
        ->and($html)->toContain('ОГРН: 1234567890123')
        ->and($html)->toContain('Юридический адрес')
        ->and($html)->toContain('г. Краснодар, ул. Тестовая, 10')
        ->and($html)->toContain('Адрес для корреспонденции')
        ->and($html)->toContain('г. Краснодар, а/я 100')
        ->and($html)->toContain('href="mailto:public@example.com"')
        ->and($html)->toContain('href="tel:+79991234567"');
});

it('omits correspondence address when it matches the legal address', function () {
    config()->set('company.legal_name', 'ООО Тестовая компания');
    config()->set('company.inn', '231102927496');
    config()->set('company.ogrn', '');
    config()->set('company.ogrnip', '123456789012345');
    config()->set('company.legal_addr', 'г. Краснодар, ул. Тестовая, 10');
    config()->set('company.correspondence_addr', 'г. Краснодар, ул. Тестовая, 10');
    config()->set('company.public_email', 'public@example.com');
    config()->set('company.phone', '+7 (999) 123-45-67');

    $html = SellerRequisitesBlock::toHtml([], []);

    expect($html)->toContain('ОГРНИП: 123456789012345')
        ->and($html)->not->toContain('Адрес для корреспонденции');
});

it('renders pdf link custom block using public storage url', function () {
    config(['filesystems.disks.public.url' => '/storage']);

    $html = PdfLinkBlock::toHtml([
        'source_type' => PdfLinkBlockConfigNormalizer::SOURCE_UPLOAD,
        'file' => 'documents/rich-content/catalog.pdf',
        'link_text' => 'Скачать каталог',
        'target' => PdfLinkBlockConfigNormalizer::TARGET_NEW_TAB,
    ], []);

    expect($html)->toContain('/storage/documents/rich-content/catalog.pdf')
        ->and($html)->toContain('Скачать каталог')
        ->and($html)->toContain('motion-safe:transition-transform')
        ->and($html)->toContain('hover:scale-[1.015]')
        ->and($html)->toContain('target="_blank"')
        ->and($html)->toContain('rel="noopener noreferrer"');
});

it('renders pdf link custom block using direct external url', function () {
    $html = PdfLinkBlock::toHtml([
        'source_type' => PdfLinkBlockConfigNormalizer::SOURCE_DIRECT_URL,
        'url' => 'https://cdn.example.test/files/price-list.pdf',
        'link_text' => 'Прайс-лист',
        'target' => PdfLinkBlockConfigNormalizer::TARGET_SAME_TAB,
    ], []);

    expect($html)->toContain('href="https://cdn.example.test/files/price-list.pdf"')
        ->and($html)->toContain('Прайс-лист')
        ->and($html)->not->toContain('target="_blank"');
});

it('renders rutube video with optional width cap', function () {
    $html = RutubeVideoBlock::toHtml([
        'rutube_id' => 'abc123',
        'width' => 640,
        'alignment' => 'left',
    ], []);

    expect($html)->toContain('style="max-width: min(100%, 640px);"')
        ->and($html)->toContain('video--left')
        ->and($html)->toContain('src="https://rutube.ru/play/embed/abc123"')
        ->and($html)->not->toContain('width="')
        ->and($html)->not->toContain('height="');
});

it('renders rutube video without width cap when missing', function () {
    $html = RutubeVideoBlock::toHtml([
        'rutube_id' => 'abc123',
    ], []);

    expect($html)->not->toContain('style="max-width:');
});

it('renders youtube video with optional width cap', function () {
    $html = YoutubeVideoBlock::toHtml([
        'video_id' => 'M7lc1UVf-VE',
        'width' => 720,
        'alignment' => 'left',
    ], []);

    expect($html)->toContain('style="max-width: min(100%, 720px);"')
        ->and($html)->toContain('video--left')
        ->and($html)->toContain('src="https://www.youtube.com/embed/M7lc1UVf-VE"')
        ->and($html)->not->toContain('width="')
        ->and($html)->not->toContain('height="');
});

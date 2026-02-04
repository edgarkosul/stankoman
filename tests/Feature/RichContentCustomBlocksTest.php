<?php

use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageGalleryBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RawHtmlBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RutubeVideoBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YoutubeVideoBlock;
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

it('renders raw html custom block without modification', function () {
    $html = RawHtmlBlock::toHtml([
        'html' => '<div data-test="raw">OK</div>',
    ], []);

    expect($html)->toBe('<div data-test="raw">OK</div>');
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

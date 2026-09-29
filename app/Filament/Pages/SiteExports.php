<?php

namespace App\Filament\Pages;

use App\Jobs\GenerateMarketFeedJob;
use App\Jobs\GenerateSitemapFilesJob;
use App\Support\Seo\SitemapGenerator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class SiteExports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|UnitEnum|null $navigationGroup = 'Экспорт/Импорт';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'SEO и фиды';

    protected static ?string $title = 'SEO файлы и фиды сайта';

    protected static ?string $slug = 'site-exports';

    protected string $view = 'filament.pages.site-exports';

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_sitemap')
                ->label('Сгенерировать sitemap')
                ->icon('heroicon-o-map')
                ->color('primary')
                ->action('queueSitemapGeneration'),
            Action::make('generate_market_feed')
                ->label('Сгенерировать market.xml')
                ->icon('heroicon-o-document-text')
                ->color('success')
                ->action('queueMarketFeedGeneration'),
        ];
    }

    public function queueSitemapGeneration(): void
    {
        GenerateSitemapFilesJob::dispatch(Auth::id());

        Notification::make()
            ->success()
            ->title('Генерация sitemap поставлена в очередь')
            ->body('После завершения придет уведомление в Filament.')
            ->send();
    }

    public function queueMarketFeedGeneration(): void
    {
        GenerateMarketFeedJob::dispatch(Auth::id());

        Notification::make()
            ->success()
            ->title('Генерация market.xml поставлена в очередь')
            ->body('После завершения придет уведомление в Filament.')
            ->send();
    }

    /**
     * @return list<array{
     *     title: string,
     *     description: string,
     *     public_url: string,
     *     command: string,
     *     files: list<array{
     *         label: string,
     *         path: string,
     *         exists: bool,
     *         updated_at: ?string,
     *         size: ?string,
     *         note: ?string
     *     }>
     * }>
     */
    public function exportCards(): array
    {
        return [
            $this->sitemapCard(),
            $this->marketFeedCard(),
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     public_url: string,
     *     command: string,
     *     files: list<array{
     *         label: string,
     *         path: string,
     *         exists: bool,
     *         updated_at: ?string,
     *         size: ?string,
     *         note: ?string
     *     }>
     * }
     */
    private function sitemapCard(): array
    {
        $sitemaps = app(SitemapGenerator::class);
        $productFiles = array_values(File::glob($sitemaps->path('sitemap-products-*.xml')) ?: []);

        return [
            'title' => 'Sitemap',
            'description' => 'Индекс sitemap, статика, категории и product-sitemap для поисковых систем. robots.txt файлом не хранится — сайт собирает его на лету.',
            'public_url' => $this->absoluteUrl('/sitemap.xml'),
            'command' => 'php artisan seo:generate-sitemap',
            'files' => [
                $this->fileState('sitemap.xml', $sitemaps->path('sitemap.xml'), 'Главный индекс карты сайта'),
                $this->fileState('sitemap-static.xml', $sitemaps->path('sitemap-static.xml'), 'Главная и опубликованные страницы'),
                $this->fileState('sitemap-categories.xml', $sitemaps->path('sitemap-categories.xml'), 'Маршруты категорий каталога'),
                $this->groupedFileState(
                    'sitemap-products-*.xml',
                    $sitemaps->path('sitemap-products-*.xml'),
                    $productFiles,
                    $productFiles === [] ? 'Файлы еще не сгенерированы' : sprintf('Найдено файлов: %d', count($productFiles)),
                ),
            ],
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     public_url: string,
     *     command: string,
     *     files: list<array{
     *         label: string,
     *         path: string,
     *         exists: bool,
     *         updated_at: ?string,
     *         size: ?string,
     *         note: ?string
     *     }>
     * }
     */
    private function marketFeedCard(): array
    {
        $disk = Storage::disk('public');
        $path = $disk->path('feeds/yandex-market.xml');

        return [
            'title' => 'Yandex Market feed',
            'description' => 'Публичный `/market.xml`, который отдается из `storage/app/public/feeds/yandex-market.xml`.',
            'public_url' => $this->absoluteUrl('/market.xml'),
            'command' => 'php artisan feeds:generate-market',
            'files' => [
                $this->fileState('/market.xml', $path, 'Публичный URL использует этот XML-файл'),
            ],
        ];
    }

    /**
     * @return array{
     *     label: string,
     *     path: string,
     *     exists: bool,
     *     updated_at: ?string,
     *     size: ?string,
     *     note: ?string
     * }
     */
    private function fileState(string $label, string $path, ?string $note = null): array
    {
        $exists = is_file($path);

        return [
            'label' => $label,
            'path' => $path,
            'exists' => $exists,
            'updated_at' => $exists ? $this->formatTimestamp(filemtime($path) ?: null) : null,
            'size' => $exists ? $this->formatBytes(filesize($path) ?: 0) : null,
            'note' => $note,
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return array{
     *     label: string,
     *     path: string,
     *     exists: bool,
     *     updated_at: ?string,
     *     size: ?string,
     *     note: ?string
     * }
     */
    private function groupedFileState(string $label, string $pathPattern, array $paths, ?string $note = null): array
    {
        $latestTimestamp = null;
        $bytes = 0;

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $timestamp = filemtime($path) ?: null;

            if (is_int($timestamp) && ($latestTimestamp === null || $timestamp > $latestTimestamp)) {
                $latestTimestamp = $timestamp;
            }

            $bytes += filesize($path) ?: 0;
        }

        return [
            'label' => $label,
            'path' => $pathPattern,
            'exists' => $paths !== [],
            'updated_at' => $this->formatTimestamp($latestTimestamp),
            'size' => $paths !== [] ? $this->formatBytes($bytes) : null,
            'note' => $note,
        ];
    }

    private function absoluteUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('company.site_url', config('app.url')), '/');
        $normalizedPath = '/'.ltrim($path, '/');

        return $baseUrl.$normalizedPath;
    }

    private function formatTimestamp(?int $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp)
            ->timezone((string) config('app.timezone'))
            ->format('d.m.Y H:i:s');
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        $precision = $unitIndex === 0 ? 0 : 1;

        return number_format($size, $precision, '.', ' ').' '.$units[$unitIndex];
    }
}

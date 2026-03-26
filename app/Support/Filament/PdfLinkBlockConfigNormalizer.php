<?php

namespace App\Support\Filament;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PdfLinkBlockConfigNormalizer
{
    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_DOWNLOAD_URL = 'download_url';

    public const SOURCE_DIRECT_URL = 'direct_url';

    public const TARGET_SAME_TAB = '_self';

    public const TARGET_NEW_TAB = '_blank';

    public const DISK = 'public';

    public const DIRECTORY = 'documents/rich-content';

    public const MAX_FILE_SIZE_KB = 20_480;

    /**
     * @param  array<string, mixed>  $data
     * @return array{source_type:string,target:string,link_text:string,file:?string,url:?string}
     */
    public function normalize(array $data): array
    {
        $sourceType = $this->normalizeSourceType($data['source_type'] ?? null);
        $target = $this->normalizeTarget($data['target'] ?? null);
        $linkText = $this->sanitizeString($data['link_text'] ?? null);

        return match ($sourceType) {
            self::SOURCE_DOWNLOAD_URL => $this->normalizeDownloadedPdfConfig($data, $linkText, $target),
            self::SOURCE_DIRECT_URL => $this->normalizeDirectPdfConfig($data, $linkText, $target),
            default => $this->normalizeUploadedPdfConfig($data, $linkText, $target),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{source_type:string,target:string,link_text:string,file:string,url:null}
     */
    private function normalizeUploadedPdfConfig(array $data, ?string $linkText, string $target): array
    {
        $file = $this->sanitizeString($data['file'] ?? null);

        if ($file === null) {
            throw ValidationException::withMessages([
                'file' => 'Загрузите PDF-файл.',
            ]);
        }

        return [
            'source_type' => self::SOURCE_UPLOAD,
            'target' => $target,
            'link_text' => $linkText ?? $this->resolveFallbackLinkText(
                $this->sanitizeString($data['original_file_name'] ?? null),
                $file,
            ),
            'file' => $file,
            'url' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{source_type:string,target:string,link_text:string,file:string,url:string}
     */
    private function normalizeDownloadedPdfConfig(array $data, ?string $linkText, string $target): array
    {
        $url = $this->normalizeRemoteUrl($data['url'] ?? null);
        $download = $this->downloadRemotePdf($url);

        return [
            'source_type' => self::SOURCE_DOWNLOAD_URL,
            'target' => $target,
            'link_text' => $linkText ?? $this->resolveFallbackLinkText($download['file_name'], $url),
            'file' => $download['path'],
            'url' => $url,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{source_type:string,target:string,link_text:string,file:null,url:string}
     */
    private function normalizeDirectPdfConfig(array $data, ?string $linkText, string $target): array
    {
        $url = $this->normalizeRemoteUrl($data['url'] ?? null);

        return [
            'source_type' => self::SOURCE_DIRECT_URL,
            'target' => $target,
            'link_text' => $linkText ?? $this->resolveFallbackLinkText(null, $url),
            'file' => null,
            'url' => $url,
        ];
    }

    private function normalizeSourceType(mixed $value): string
    {
        $value = $this->sanitizeString($value);

        return match ($value) {
            self::SOURCE_DOWNLOAD_URL,
            self::SOURCE_DIRECT_URL => $value,
            default => self::SOURCE_UPLOAD,
        };
    }

    private function normalizeTarget(mixed $value): string
    {
        return $value === self::TARGET_SAME_TAB
            ? self::TARGET_SAME_TAB
            : self::TARGET_NEW_TAB;
    }

    private function normalizeRemoteUrl(mixed $value): string
    {
        $url = $this->sanitizeString($value);

        if ($url === null) {
            throw ValidationException::withMessages([
                'url' => 'Укажите URL PDF-файла.',
            ]);
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'url' => 'Укажите корректный URL.',
            ]);
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::withMessages([
                'url' => 'Поддерживаются только ссылки http:// и https://.',
            ]);
        }

        return $url;
    }

    /**
     * @return array{path:string,file_name:?string}
     */
    private function downloadRemotePdf(string $url): array
    {
        try {
            $response = Http::accept('application/pdf')
                ->connectTimeout(5)
                ->timeout(20)
                ->get($url)
                ->throw();
        } catch (RequestException $exception) {
            $status = $exception->response?->status();

            throw ValidationException::withMessages([
                'url' => filled($status)
                    ? "Не удалось скачать PDF по URL. Сервер вернул HTTP {$status}."
                    : 'Не удалось скачать PDF по URL.',
            ]);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'url' => 'Не удалось скачать PDF по URL.',
            ]);
        }

        $body = $response->body();

        if ($body === '') {
            throw ValidationException::withMessages([
                'url' => 'По указанному URL пришёл пустой ответ.',
            ]);
        }

        if (strlen($body) > self::MAX_FILE_SIZE_KB * 1024) {
            throw ValidationException::withMessages([
                'url' => 'PDF по URL слишком большой. Максимум 20 МБ.',
            ]);
        }

        if (! $this->responseLooksLikePdf($url, $body, $response->header('Content-Type'))) {
            throw ValidationException::withMessages([
                'url' => 'По указанному URL получен файл, который не похож на PDF.',
            ]);
        }

        $remoteFileName = $this->extractDownloadFileName($url, $response->header('Content-Disposition'));
        $path = $this->buildStoragePath($remoteFileName);

        if (! Storage::disk(self::DISK)->put($path, $body)) {
            throw ValidationException::withMessages([
                'url' => 'Не удалось сохранить скачанный PDF в локальное хранилище.',
            ]);
        }

        return [
            'path' => $path,
            'file_name' => $remoteFileName,
        ];
    }

    private function responseLooksLikePdf(string $url, string $body, ?string $contentType): bool
    {
        $contentType = Str::lower((string) $contentType);
        $urlPath = Str::lower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($contentType, 'application/pdf')
            || str_ends_with($urlPath, '.pdf')
            || preg_match('/\A\s*%PDF-/s', substr($body, 0, 32)) === 1;
    }

    private function extractDownloadFileName(string $url, ?string $contentDisposition): ?string
    {
        $fileNameFromDisposition = $this->extractDispositionFileName($contentDisposition);

        if ($fileNameFromDisposition !== null) {
            return $fileNameFromDisposition;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return $this->sanitizeString(urldecode(basename($path)));
    }

    private function extractDispositionFileName(?string $contentDisposition): ?string
    {
        if (! is_string($contentDisposition) || $contentDisposition === '') {
            return null;
        }

        if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $contentDisposition, $matches) === 1) {
            return $this->sanitizeString(urldecode($matches[1]));
        }

        if (preg_match('/filename="?([^";]+)"?/i', $contentDisposition, $matches) === 1) {
            return $this->sanitizeString($matches[1]);
        }

        return null;
    }

    private function buildStoragePath(?string $remoteFileName): string
    {
        $baseName = $remoteFileName !== null
            ? pathinfo($remoteFileName, PATHINFO_FILENAME)
            : 'pdf-document';
        $slug = Str::slug($baseName);

        if ($slug === '') {
            $slug = 'pdf-document';
        }

        return self::DIRECTORY.'/'.now()->format('Y/m').'/'.$slug.'-'.Str::lower(Str::random(8)).'.pdf';
    }

    private function resolveFallbackLinkText(?string $preferred, ?string $fallback): string
    {
        foreach ([$preferred, $fallback] as $candidate) {
            $fileName = $this->extractFileName($candidate);

            if ($fileName !== null) {
                return $fileName;
            }
        }

        return 'PDF документ';
    }

    private function extractFileName(?string $value): ?string
    {
        $value = $this->sanitizeString($value);

        if ($value === null) {
            return null;
        }

        $path = (string) parse_url($value, PHP_URL_PATH);
        $candidate = basename($path !== '' ? $path : $value);

        return $this->sanitizeString(urldecode($candidate));
    }

    private function sanitizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

<?php

namespace App\Support\Seo;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SiteSeoDataBuilder
{
    public function __construct(
        protected SeoTextExtractor $textExtractor
    ) {}

    /**
     * @param  array{
     *     title?: ?string,
     *     description?: ?string,
     *     url?: ?string,
     *     image?: ?string,
     *     type?: ?string,
     *     schemas?: array<int, array<string, mixed>>,
     *     robots?: ?string
     * }  $input
     * @return array{
     *     title: string,
     *     description: string,
     *     canonical: string,
     *     image: string,
     *     robots: string,
     *     locale: string,
     *     site_name: string,
     *     og: array<string, string>,
     *     twitter: array<string, string>,
     *     schemas: array<int, array<string, mixed>>
     * }
     */
    public function build(array $input = []): array
    {
        $siteName = (string) config('app.name');
        $defaultImage = $this->absoluteUrl('/apple-touch-icon.png');
        $canonical = $this->resolveCanonicalUrl($input['url'] ?? null);
        $title = $this->resolveTitle($input['title'] ?? null, $siteName);
        $description = $this->resolveDescription($input['description'] ?? null, $siteName);
        $image = $this->resolveImage($input['image'] ?? null, $defaultImage);
        $type = $this->resolveType($input['type'] ?? null);
        $robots = trim((string) ($input['robots'] ?? 'index,follow'));
        $schemas = array_values(array_filter([
            $this->organizationSchema(),
            $this->websiteSchema(),
            ...$this->normalizeSchemas($input['schemas'] ?? []),
        ]));

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'image' => $image,
            'robots' => $robots,
            'locale' => str_replace('_', '-', app()->getLocale()),
            'site_name' => $siteName,
            'og' => [
                'title' => $title,
                'description' => $description,
                'type' => $type,
                'url' => $canonical,
                'image' => $image,
                'site_name' => $siteName,
                'locale' => str_replace('_', '-', app()->getLocale()),
            ],
            'twitter' => [
                'card' => 'summary_large_image',
                'title' => $title,
                'description' => $description,
                'image' => $image,
            ],
            'schemas' => $schemas,
        ];
    }

    protected function resolveTitle(?string $title, string $siteName): string
    {
        $title = trim((string) $title);

        if ($title === '') {
            return $siteName;
        }

        if ($title === $siteName) {
            return $siteName;
        }

        return $title.' | '.$siteName;
    }

    protected function resolveDescription(?string $description, string $siteName): string
    {
        $plain = trim(strip_tags((string) $description));

        if ($plain !== '') {
            return Str::limit($plain, 200, '…');
        }

        return 'Каталог промышленного оборудования, станков и оснастки от '.$siteName.'.';
    }

    protected function resolveImage(?string $image, string $defaultImage): string
    {
        $image = trim((string) $image);

        if ($image === '') {
            return $defaultImage;
        }

        if (Str::startsWith($image, ['http://', 'https://'])) {
            return $image;
        }

        return $this->absoluteUrl($image);
    }

    protected function resolveType(?string $type): string
    {
        $type = trim((string) $type);

        return $type !== '' ? $type : 'website';
    }

    protected function resolveCanonicalUrl(?string $url): string
    {
        $url = trim((string) $url);

        if ($url !== '') {
            return Str::startsWith($url, ['http://', 'https://'])
                ? $url
                : $this->absoluteUrl($url);
        }

        return request()->fullUrl();
    }

    /**
     * @param  array<int, array<string, mixed>>  $schemas
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeSchemas(array $schemas): array
    {
        return array_values(array_filter(
            $schemas,
            static fn (mixed $schema): bool => is_array($schema) && Arr::has($schema, ['@context', '@type'])
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function organizationSchema(): array
    {
        $shopName = (string) config('settings.general.shop_name', config('app.name'));
        $brandLine = trim((string) config('company.brand_line'));
        $legalName = trim((string) config('company.legal_name'));
        $siteUrl = trim((string) config('company.site_url', $this->absoluteUrl('/')));
        $phone = trim((string) config('company.phone'));
        $address = trim((string) config('company.legal_addr'));
        $email = trim((string) config('company.public_email'));

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $shopName,
            'alternateName' => $brandLine !== '' ? $brandLine : null,
            'legalName' => $legalName !== '' ? $legalName : null,
            'url' => $siteUrl !== '' ? $siteUrl : $this->absoluteUrl('/'),
            'logo' => $this->absoluteUrl('/apple-touch-icon.png'),
            'telephone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'contactPoint' => ($phone !== '' || $email !== '')
                ? [[
                    '@type' => 'ContactPoint',
                    'contactType' => 'customer support',
                    'telephone' => $phone !== '' ? $phone : null,
                    'email' => $email !== '' ? $email : null,
                    'url' => $siteUrl !== '' ? $siteUrl : null,
                ]]
                : null,
            'address' => $address !== ''
                ? [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $address,
                ]
                : null,
        ];

        return array_filter($schema, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function websiteSchema(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => config('app.name'),
            'url' => $this->absoluteUrl('/'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => $this->absoluteUrl('/search?q={search_term_string}'),
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    protected function absoluteUrl(string $path): string
    {
        return url($path);
    }

    public function descriptionFromHtml(?string $html): ?string
    {
        return $this->textExtractor->extractDescriptionFromHtml($html);
    }
}

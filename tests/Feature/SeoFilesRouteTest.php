<?php

use App\Support\Seo\SitemapGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');

    $this->sitemaps = app(SitemapGenerator::class);
});

it('serves robots.txt without any generated file', function (): void {
    config()->set('company.site_url', 'https://settings.example.com');
    config()->set('app.robots_allow_indexing', true);

    $response = $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=utf-8');

    expect($response->getContent())->toContain('User-agent: *')
        ->toContain('Disallow: /admin/')
        ->toContain('Disallow: /*/print')
        ->toContain('Sitemap: https://settings.example.com/sitemap.xml');
});

it('serves a blocking robots.txt when indexing is disabled', function (): void {
    config()->set('app.robots_allow_indexing', false);

    expect($this->get('/robots.txt')->assertOk()->getContent())
        ->toBe("User-agent: *\nDisallow: /\n");
});

it('serves generated sitemap files', function (string $filename): void {
    File::ensureDirectoryExists($this->sitemaps->directory());
    File::put($this->sitemaps->path($filename), '<urlset>'.$filename.'</urlset>');

    $response = $this->get('/'.$filename)
        ->assertOk()
        ->assertHeader('content-type', 'text/xml; charset=utf-8');

    expect(File::get($response->baseResponse->getFile()->getPathname()))
        ->toBe('<urlset>'.$filename.'</urlset>');
})->with([
    'sitemap.xml',
    'sitemap-static.xml',
    'sitemap-categories.xml',
    'sitemap-products-12.xml',
]);

it('returns 404 when the sitemap has not been generated yet', function (): void {
    $this->get('/sitemap.xml')->assertNotFound();
});

it('does not serve arbitrary files from the sitemap directory', function (): void {
    File::ensureDirectoryExists($this->sitemaps->directory());
    File::put($this->sitemaps->path('sitemap-products-1.xml.tmp'), '<urlset/>');
    File::put($this->sitemaps->path('sitemap-secret.xml'), '<urlset/>');

    $this->get('/sitemap-products-1.xml.tmp')->assertNotFound();
    $this->get('/sitemap-secret.xml')->assertNotFound();
});

<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class MetalmasterCatalogCategorySeeder extends Seeder
{
    /** @var array<string, true> */
    private array $productUrls = [];

    /** @var array<string, true> */
    private array $seededUrls = [];

    private int $createdCategories = 0;

    private int $skippedProductLeaves = 0;

    public function run(): void
    {
        $treeNodes = $this->loadTreeNodes($this->treeFilePath());
        $this->productUrls = $this->loadProductUrls($this->bucketsFilePath());

        $this->resetCategories();
        $this->seedNodes($treeNodes, Category::defaultParentKey());

        $this->command?->info('Metalmaster categories created: '.$this->createdCategories);
        $this->command?->line('Skipped product leaves: '.$this->skippedProductLeaves);
    }

    private function treeFilePath(): string
    {
        return storage_path('app/parser/metalmaster-catalog-tree.json');
    }

    private function bucketsFilePath(): string
    {
        return storage_path('app/parser/metalmaster-buckets.json');
    }

    private function resetCategories(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach (['category_attribute', 'product_categories', 'categories'] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                DB::table($table)->truncate();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadTreeNodes(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Metalmaster tree file not found: {$path}");
        }

        $raw = file_get_contents($path);

        if (! is_string($raw)) {
            throw new RuntimeException("Unable to read Metalmaster tree file: {$path}");
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new RuntimeException("Invalid Metalmaster tree JSON: {$path}");
        }

        if (array_is_list($decoded)) {
            return array_values(array_filter($decoded, 'is_array'));
        }

        $tree = $decoded['tree'] ?? null;

        if (! is_array($tree)) {
            throw new RuntimeException("Tree root is missing in file: {$path}");
        }

        return array_values(array_filter($tree, 'is_array'));
    }

    /**
     * @return array<string, true>
     */
    private function loadProductUrls(string $path): array
    {
        if (! is_file($path)) {
            $this->command?->warn("Buckets file not found, product-leaf filtering is disabled: {$path}");

            return [];
        }

        $raw = file_get_contents($path);

        if (! is_string($raw)) {
            $this->command?->warn("Unable to read buckets file, product-leaf filtering is disabled: {$path}");

            return [];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            $this->command?->warn("Invalid buckets JSON, product-leaf filtering is disabled: {$path}");

            return [];
        }

        $urls = [];

        foreach ($this->bucketRows($decoded) as $row) {
            $productUrls = $row['product_urls'] ?? null;

            if (! is_array($productUrls)) {
                continue;
            }

            foreach ($productUrls as $productUrl) {
                if (! is_string($productUrl)) {
                    continue;
                }

                $normalized = $this->normalizeUrl($productUrl);

                if ($normalized === null) {
                    continue;
                }

                $urls[$normalized] = true;
            }
        }

        return $urls;
    }

    /**
     * @param  array<int|string, mixed>  $decoded
     * @return array<int, array<string, mixed>>
     */
    private function bucketRows(array $decoded): array
    {
        if (array_is_list($decoded)) {
            return array_values(array_filter($decoded, 'is_array'));
        }

        $rows = $decoded['buckets'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function seedNodes(array $nodes, int $parentId): void
    {
        $order = 1;

        foreach ($nodes as $node) {
            $title = trim((string) ($node['title'] ?? $node['name'] ?? ''));
            $url = $this->normalizeUrl((string) ($node['url'] ?? ''));
            $children = $this->childNodes($node);

            if ($title === '' && $url === null) {
                continue;
            }

            if ($url !== null && isset($this->productUrls[$url])) {
                $this->skippedProductLeaves++;

                continue;
            }

            if ($url !== null && isset($this->seededUrls[$url])) {
                continue;
            }

            $slug = $this->resolveSlug($node, $title, $url);
            $name = $this->resolveName($title, $slug);

            $category = $this->createCategory(
                parentId: $parentId,
                name: $name,
                slugBase: $slug,
                order: $order,
                url: $url,
            );

            $order++;

            if ($url !== null) {
                $this->seededUrls[$url] = true;
            }

            if ($children !== []) {
                $this->seedNodes($children, (int) $category->getKey());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<int, array<string, mixed>>
     */
    private function childNodes(array $node): array
    {
        $children = $node['children'] ?? null;

        if (! is_array($children)) {
            return [];
        }

        return array_values(array_filter($children, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function resolveSlug(array $node, string $title, ?string $url): string
    {
        $path = trim((string) ($node['path'] ?? ''), '/');

        if ($path !== '') {
            $segments = explode('/', $path);
            $lastSegment = trim((string) end($segments));

            if ($lastSegment !== '') {
                return $lastSegment;
            }
        }

        if ($url !== null) {
            $urlPath = trim((string) parse_url($url, PHP_URL_PATH), '/');

            if ($urlPath !== '') {
                $segments = explode('/', $urlPath);
                $lastSegment = trim((string) end($segments));

                if ($lastSegment !== '') {
                    return $lastSegment;
                }
            }
        }

        $slug = Str::slug($title);

        if ($slug !== '') {
            return $slug;
        }

        return 'category-'.Str::lower(Str::random(8));
    }

    private function resolveName(string $title, string $slug): string
    {
        if ($title !== '') {
            return $title;
        }

        return Str::headline(str_replace('_', ' ', $slug));
    }

    private function createCategory(int $parentId, string $name, string $slugBase, int $order, ?string $url): Category
    {
        $slug = $this->uniqueSlug($parentId, $slugBase);

        $category = Category::query()->create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'img' => null,
            'is_active' => true,
            'order' => $order,
            'meta_description' => "Раздел {$name} каталога Metalmaster.",
            'meta_json' => [
                'source' => 'metalmaster-catalog-tree',
                'url' => $url,
            ],
        ]);

        $this->createdCategories++;

        return $category;
    }

    private function uniqueSlug(int $parentId, string $slugBase): string
    {
        $slug = $slugBase;
        $counter = 2;

        while (Category::query()->where('parent_id', $parentId)->where('slug', $slug)->exists()) {
            $slug = "{$slugBase}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('~/+~', '/', $path);

        if (! is_string($path) || $path === '') {
            $path = '/';
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return "{$scheme}://{$host}{$path}";
    }
}

<?php

namespace App\Providers;

use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Filament\Support\Assets\Js;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentTimezone;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerFilamentAssets();
        $this->registerViewComposers();
        FilamentTimezone::set('Europe/Moscow');
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn(): ?Password => app()->isProduction()
                ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
                : null
        );
    }

    protected function registerFilamentAssets(): void
    {
        FilamentAsset::register([
            Js::make(
                'rich-content-plugins/text-size',
                resource_path('js/dist/filament/rich-content-plugins/text-size.js'),
            )->loadedOnRequest(),
        ]);
    }

    protected function registerViewComposers(): void
    {
        View::composer('components.layouts.partials.header', function ($view) {
            $menu = Cache::remember('catalog.menu.v1', now()->addMinutes(30), function () {
                return $this->buildCatalogMenu();
            });

            $roots = $menu['roots'] ?? collect();
            $activeRootId = $this->resolveActiveRootId($roots);

            $view->with([
                'catalogMenuRoots' => $roots,
                'catalogMenuActiveRootId' => $activeRootId,
            ]);
        });
    }

    protected function buildCatalogMenu(): array
    {
        $brandSlug = 'vybor-po-proizvoditelyu';

        $roots = Category::query()
            ->where('parent_id', Category::defaultParentKey())
            ->where('is_active', true)
            ->orderBy('order')
            ->get(['id', 'name', 'slug', 'parent_id', 'order'])
            ->reject(fn(Category $root) => $root->slug === $brandSlug)
            ->values();

        if ($roots->isEmpty()) {
            return ['roots' => collect()];
        }

        $children = Category::query()
            ->whereIn('parent_id', $roots->pluck('id'))
            ->where('is_active', true)
            ->orderBy('order')
            ->get(['id', 'name', 'slug', 'parent_id', 'order'])
            ->reject(fn(Category $child) => $child->slug === $brandSlug)
            ->values();

        $grandchildren = $children->isEmpty()
            ? collect()
            : Category::query()
            ->whereIn('parent_id', $children->pluck('id'))
            ->where('is_active', true)
            ->orderBy('order')
            ->get(['id', 'name', 'slug', 'parent_id', 'order'])
            ->reject(fn(Category $grandchild) => $grandchild->slug === $brandSlug)
            ->values();

        $childrenByParent = $children->groupBy('parent_id');
        $grandchildrenByParent = $grandchildren->groupBy('parent_id');

        $roots = $roots->map(function (Category $root) use ($childrenByParent, $grandchildrenByParent) {
            $rootPath = $root->slug;
            $children = $childrenByParent->get($root->id, collect())->map(
                function (Category $child) use ($rootPath, $grandchildrenByParent) {
                    $childPath = $rootPath . '/' . $child->slug;
                    $grandchildren = $grandchildrenByParent->get($child->id, collect())->map(
                        function (Category $grandchild) use ($childPath) {
                            return [
                                'id' => $grandchild->id,
                                'name' => $grandchild->name,
                                'menu_path' => $childPath . '/' . $grandchild->slug,
                            ];
                        }
                    )->values();

                    return [
                        'id' => $child->id,
                        'name' => $child->name,
                        'slug' => $child->slug,
                        'menu_path' => $childPath,
                        'children' => $grandchildren,
                    ];
                }
            )->values();

            return [
                'id' => $root->id,
                'name' => $root->name,
                'slug' => $root->slug,
                'menu_path' => $rootPath,
                'children' => $children,
            ];
        })->values();

        return ['roots' => $roots];
    }

    protected function resolveActiveRootId(Collection $roots): ?int
    {
        if ($roots->isEmpty()) {
            return null;
        }

        $path = request()->route('path');
        if (is_string($path)) {
            $slug = Str::of($path)->trim('/')->explode('/')->first();
            if ($slug) {
                $matched = $roots->firstWhere('slug', $slug);
                if ($matched) {
                    return $matched['id'];
                }
            }
        }

        return $roots->first()['id'];
    }
}

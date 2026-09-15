<?php

namespace App\Providers;

use App\Filament\Forms\Components\RichEditor\TipTapExtensions\ImageExtension as AppImageExtension;
use App\Listeners\Chat\AttachConversationToUser;
use App\Listeners\Chat\ForgetConversationCookie;
use App\Listeners\CloneCartOnLogout;
use App\Listeners\SyncCartOnLogin;
use App\Listeners\SyncFavoritesOnLogin;
use App\Listeners\SyncFavoritesOnLogout;
use App\Models\Category;
use App\Services\Captcha\CaptchaManager;
use App\Support\CartService;
use App\Support\CompareService;
use App\Support\FavoritesService;
use App\Support\Seo\SiteSeoDataBuilder;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\RichEditor\TipTapExtensions\ImageExtension as FilamentImageExtension;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Капча одна на весь магазин: и чат, и формы витрины спрашивают
         * у неё, нужна ли проверка и пускать ли токен. Синглтон, потому
         * что верификатор внутри мемоизируется.
         */
        $this->app->singleton(CaptchaManager::class, fn (): CaptchaManager => new CaptchaManager(
            switchedOn: (bool) config('captcha.enabled'),
            driver: (string) config('captcha.driver', 'null'),
            drivers: (array) config('captcha.drivers', []),
        ));

        $this->app->singleton(CompareService::class, fn (): CompareService => new CompareService);
        $this->app->singleton(CartService::class, fn (): CartService => new CartService);
        $this->app->scoped(FavoritesService::class, fn (): FavoritesService => new FavoritesService);
        $this->app->bind(FilamentImageExtension::class, AppImageExtension::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->throttleLivewireUpdates();
        $this->registerAuthEventListeners();
        $this->registerFilamentAssets();
        $this->registerViewComposers();
        FilamentTimezone::set('Europe/Moscow');
    }

    /**
     * Потолок частоты на единственный эндпоинт, через который идут ВСЕ
     * действия Livewire.
     *
     * Он же самый дорогой на сайте: за одним запросом стоит гидрация
     * компонента, а за некоторыми — платный вызов модели (вопрос боту).
     * Оставлять его без потолка, когда на нём висят чужие деньги, нельзя.
     *
     * Задет при этом весь сайт: корзина, фильтры каталога, подсказки поиска,
     * модалки авторизации. Поэтому 120 в минуту, а не «поменьше для
     * надёжности»: фильтрация каталога умеет всплески (каждая галка — запрос),
     * а живой посетитель в логах прода не даёт больше ~40 запросов в минуту
     * на ВСЁ вместе. Первым слоем всё равно стоит nginx — см.
     * `scripts/deploy/nginx/`.
     *
     * Путь берём тот, что передаёт Livewire: с четвёртой версии он
     * не `/livewire/update`, а `/livewire-<хэш от APP_KEY>/update`, и на дев-
     * и прод-контуре он разный. Свой литерал здесь означал бы второй маршрут
     * рядом с настоящим — то есть потолок, которого нет.
     */
    protected function throttleLivewireUpdates(): void
    {
        Livewire::setUpdateRoute(fn ($handle, string $path) => Route::post($path, $handle)
            ->middleware(['web', 'throttle:120,1']));
    }

    protected function registerAuthEventListeners(): void
    {
        Event::listen(Login::class, SyncCartOnLogin::class);
        Event::listen(Logout::class, CloneCartOnLogout::class);
        Event::listen(Login::class, SyncFavoritesOnLogin::class);
        Event::listen(Logout::class, SyncFavoritesOnLogout::class);
        Event::listen(Login::class, AttachConversationToUser::class);
        Event::listen(Logout::class, ForgetConversationCookie::class);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
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
        View::composer('components.layouts.app', function ($view): void {
            $builder = app(SiteSeoDataBuilder::class);
            $data = $view->getData();

            $view->with('head', $builder->build([
                'title' => $data['title'] ?? null,
                'description' => data_get($data, 'seo.description'),
                'url' => data_get($data, 'seo.url'),
                'image' => data_get($data, 'seo.image'),
                'type' => data_get($data, 'seo.type'),
                'schemas' => data_get($data, 'seo.schemas', []),
                'robots' => data_get($data, 'seo.robots'),
            ]));
        });

        View::composer('components.layouts.partials.header', function ($view) {
            $menu = Cache::remember(Category::CATALOG_MENU_CACHE_KEY, now()->addMinutes(30), function () {
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
            ->withoutStaging()
            ->where('parent_id', Category::defaultParentKey())
            ->where('is_active', true)
            ->orderBy('order')
            ->get(['id', 'name', 'slug', 'parent_id', 'order'])
            ->reject(fn (Category $root) => $root->slug === $brandSlug)
            ->values();

        if ($roots->isEmpty()) {
            return ['roots' => collect()];
        }

        $children = Category::query()
            ->withoutStaging()
            ->whereIn('parent_id', $roots->pluck('id'))
            ->where('is_active', true)
            ->orderBy('order')
            ->get(['id', 'name', 'slug', 'parent_id', 'order'])
            ->reject(fn (Category $child) => $child->slug === $brandSlug)
            ->values();

        $grandchildren = $children->isEmpty()
            ? collect()
            : Category::query()
                ->withoutStaging()
                ->whereIn('parent_id', $children->pluck('id'))
                ->where('is_active', true)
                ->orderBy('order')
                ->get(['id', 'name', 'slug', 'parent_id', 'order'])
                ->reject(fn (Category $grandchild) => $grandchild->slug === $brandSlug)
                ->values();

        $childrenByParent = $children->groupBy('parent_id');
        $grandchildrenByParent = $grandchildren->groupBy('parent_id');

        $roots = $roots->map(function (Category $root) use ($childrenByParent, $grandchildrenByParent) {
            $rootPath = $root->slug;
            $children = $childrenByParent->get($root->id, collect())->map(
                function (Category $child) use ($rootPath, $grandchildrenByParent) {
                    $childPath = $rootPath.'/'.$child->slug;
                    $grandchildren = $grandchildrenByParent->get($child->id, collect())->map(
                        function (Category $grandchild) use ($childPath) {
                            return [
                                'id' => $grandchild->id,
                                'name' => $grandchild->name,
                                'menu_path' => $childPath.'/'.$grandchild->slug,
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

<?php

namespace App\Providers\Filament;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Http\Middleware\TrackOperatorActivity;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Siteko\FilamentResticBackups\Filament\ResticBackupsPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $plugins = [];

        // Avoid hard failures during Composer uninstall/update when optional packages are temporarily unavailable.
        if (class_exists(ResticBackupsPlugin::class)) {
            $plugins[] = ResticBackupsPlugin::make();
        }

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->darkMode(false)
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                // Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                // AccountWidget::class,
                // FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                /*
                 * Работа в админке = «кто-то на связи» для чата на витрине.
                 * После Authenticate, а не среди общих посредников, как
                 * у донора: там «на смене» включал бы любой, кто открыл
                 * /admin/login, — включая краулеры, которые его и открывают.
                 */
                TrackOperatorActivity::class,
            ])
            ->homeUrl(fn (): string => route('home'))
            ->brandName(fn (): string => $this->resolveBrandName())
            ->brandLogo(asset('images/logo.svg'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('favicon.svg'))
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn (): View => view('filament.components.storefront-login-notice'),
            )
            ->renderHook(
                PanelsRenderHook::PAGE_HEADER_ACTIONS_AFTER,
                fn (): View => view('filament.components.help-center-link'),
            )
            ->renderHook(
                PanelsRenderHook::PAGE_END,
                fn (): View => view('filament.components.product-edit-scroll-top'),
                scopes: EditProduct::class,
            )
            /*
             * Переключатель «я на смене» рядом с профилем. От него зависит,
             * предложит ли чат покупателю позвать менеджера или сразу
             * попросит контакты, — поэтому он должен быть на глазах,
             * а не в настройках.
             */
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => Blade::render('@livewire(\'admin.operator-presence-toggle\')'),
            )
            ->navigationGroups([
                NavigationGroup::make('Категории')->collapsed(),
                NavigationGroup::make('Продажи')->collapsed(),
                NavigationGroup::make('Экспорт/Импорт')->collapsed(),
                NavigationGroup::make('Фильтры')->collapsed(),
                NavigationGroup::make('Контент')->collapsed(),
                NavigationGroup::make('Меню')->collapsed(),
                NavigationGroup::make('Настройки')->collapsed(),
                /*
                 * Единственная группа со своим значком — «звёздочки», общий
                 * знак ИИ. Цена этого решения: у пунктов группы иконок быть
                 * не может. Filament разрешает значок либо группе, либо её
                 * пунктам, и нарушение ловит не проверкой, а исключением
                 * прямо в шаблоне сайдбара — то есть падает вся админка,
                 * а не один раздел. Добавляя сюда ресурс или страницу,
                 * не давайте им `$navigationIcon`.
                 */
                NavigationGroup::make('ИИ бот')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->collapsed(),
            ])
            ->databaseNotifications(isLazy: false)
            ->databaseNotificationsPolling('10s')
            ->plugins($plugins);
    }

    protected function resolveBrandName(): string
    {
        $shopName = trim((string) config('settings.general.shop_name'));

        if ($shopName !== '') {
            return $shopName;
        }

        $siteHost = trim((string) config('company.site_host'));

        if ($siteHost !== '') {
            return $siteHost;
        }

        return (string) config('app.name');
    }
}

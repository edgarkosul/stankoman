<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
            ])
            ->brandName(fn (): string => $this->resolveBrandName())
            ->brandLogo(asset('images/logo.svg'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('favicon.svg'))
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(
                PanelsRenderHook::PAGE_HEADER_ACTIONS_AFTER,
                fn (): View => view('filament.components.help-center-link'),
            )
            ->navigationGroups([
                NavigationGroup::make('Категории')->collapsed(),
                NavigationGroup::make('Продажи')->collapsed(),
                NavigationGroup::make('Экспорт/Импорт')->collapsed(),
                NavigationGroup::make('Фильтры')->collapsed(),
                NavigationGroup::make('Контент')->collapsed(),
                NavigationGroup::make('Меню')->collapsed(),
                NavigationGroup::make('Настройки')->collapsed(),
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

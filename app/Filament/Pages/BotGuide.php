<?php

namespace App\Filament\Pages;

use App\Enums\SettingType;
use App\Filament\Resources\ChatConversations\ChatConversationResource;
use App\Filament\Resources\KbArticles\KbArticleResource;
use App\Models\Setting;
use App\Services\Ai\AssistantConfig;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Throwable;
use UnitEnum;

/**
 * «Как проверить бота» — инструкция владельцу магазина, написанная от лица
 * разработчика.
 *
 * Стоит на месте снятой анкеты «Вопросы для бота» и по той же причине:
 * письмо с инструкцией теряется в переписке, а страница в админке лежит
 * там, где владелец и так бывает, — рядом с «Диалогами» и «Статьями».
 * Поэтому все упомянутые разделы здесь ССЫЛКИ: инструкция, которая
 * велит «найдите в меню», для невнимательного читателя работает хуже.
 *
 * Ссылка на бота собирается из конфига, а не вписана текстом: ключ
 * предпросмотра однажды сменится, и второй его экземпляр в тексте
 * страницы разошёлся бы с настоящим молча.
 *
 * ⚠️ У группы «ИИ бот» есть значок, а Filament запрещает иконки у пунктов
 * такой группы — и роняет исключением всю админку, а не один раздел.
 * Поэтому `$navigationIcon` здесь нет и появляться не должен.
 */
class BotGuide extends Page
{
    /** Ключ настройки: когда инструкцию открыли впервые (снимает значок «новое»). */
    private const READ_AT = AssistantConfig::PREFIX.'guide_read_at';

    protected static string|UnitEnum|null $navigationGroup = 'ИИ бот';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Как проверить бота';

    protected static ?string $title = 'Как проверить бота';

    protected static ?string $slug = 'bot-guide';

    protected string $view = 'filament.pages.bot-guide';

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    public static function canAccess(): bool
    {
        return Auth::user()?->isFilamentAdmin() === true;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            return config('settings.'.self::READ_AT) === null ? 'новое' : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public function mount(): void
    {
        $this->markRead();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $key = (string) config('ai_support.chat.preview.key');
        $previewOnly = (bool) config('ai_support.chat.preview.enabled');

        return [
            /*
             * Пока идёт предпросмотр, сайт нужно открывать по ссылке с ключом;
             * когда гейт снят, бота видят все и ключ в адресе уже ни при чём.
             */
            'previewOnly' => $previewOnly,
            'siteUrl' => $previewOnly && $key !== ''
                ? rtrim((string) config('company.site_url', config('app.url')), '/').'/?bot='.$key
                : rtrim((string) config('company.site_url', config('app.url')), '/'),
            'chatUrl' => ChatConversationResource::getUrl('index'),
            'articlesUrl' => KbArticleResource::getUrl('index'),
            'gapsUrl' => KbGaps::getUrl(),
            'settingsUrl' => AssistantSettings::getUrl(),
        ];
    }

    /**
     * Отметить, что инструкцию открыли.
     *
     * Значок «новое» у раздела — единственный способ дозваться до того, кто
     * в админку заходит редко. Гасим его первым же открытием: иначе он
     * висел бы вечно и перестал что-либо значить.
     */
    private function markRead(): void
    {
        if (config('settings.'.self::READ_AT) !== null) {
            return;
        }

        try {
            Setting::query()->updateOrCreate(
                ['key' => self::READ_AT],
                ['value' => now()->toDateTimeString(), 'type' => SettingType::String, 'autoload' => true],
            );

            config()->set('settings.'.self::READ_AT, now()->toDateTimeString());
        } catch (Throwable) {
            // Страница-инструкция не должна падать из-за не записанной пометки.
        }
    }
}

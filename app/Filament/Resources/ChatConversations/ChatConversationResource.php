<?php

namespace App\Filament\Resources\ChatConversations;

use App\Filament\Resources\ChatConversations\Pages\ListChatConversations;
use App\Filament\Resources\ChatConversations\Pages\ViewChatConversation;
use App\Filament\Resources\ChatConversations\Tables\ChatConversationsTable;
use App\Models\ChatConversation;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «Диалоги» — переписка посетителей с ботом и с менеджерами.
 *
 * Первый пункт группы «ИИ бот»: с него начинается и работа менеджера
 * («бейдж загорелся — ответил»), и работа того, кто ведёт базу знаний
 * («увидел плохой ответ — понял почему — исправил»). Статьи и разделы
 * идут следом, потому что читают их обычно после диалога, а не до.
 */
class ChatConversationResource extends Resource
{
    protected static ?string $model = ChatConversation::class;

    /*
     * Иконки у пунктов этой группы нет намеренно, и вернуть её нельзя:
     * значок «звёздочек» висит на самой группе «ИИ бот», а Filament
     * запрещает иметь иконки одновременно у группы и у её пунктов —
     * и не молча, а исключением на рендере сайдбара, то есть падает
     * ВСЯ админка, а не один раздел.
     */

    protected static string|UnitEnum|null $navigationGroup = 'ИИ бот';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Диалоги';

    protected static ?string $modelLabel = 'диалог';

    protected static ?string $pluralModelLabel = 'диалоги';

    public static function form(Schema $schema): Schema
    {
        // Переписку не редактируют — её читают и в неё отвечают.
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return ChatConversationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChatConversations::route('/'),
            'view' => ViewChatConversation::route('/{record}'),
        ];
    }

    /**
     * Бейдж считает не все открытые диалоги, а те, где ждут человека:
     * эскалация, которую никто не взял, и разговор у оператора
     * с непрочитанным вопросом. Всё остальное ведёт бот, и напоминать
     * о нём в меню незачем — иначе цифра перестанет что-либо значить.
     *
     * Пока уведомлений менеджерам нет, этот бейдж — единственный сигнал
     * «покупатель ждёт человека». Группа «ИИ бот» в меню свёрнута, но
     * Filament показывает бейдж и на свёрнутой.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = ChatConversation::query()->awaitingStaff()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}

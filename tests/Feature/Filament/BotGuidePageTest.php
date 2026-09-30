<?php

use App\Filament\Pages\BotGuide;
use App\Filament\Resources\ChatConversations\ChatConversationResource;
use App\Filament\Resources\KbArticles\KbArticleResource;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AssistantConfig;

/*
 * Инструкция владельцу. Проверяем не текст, а то, из-за чего она перестала бы
 * работать незаметно: ссылку на бота (она собирается из конфига) и значок
 * «новое», которым до невнимательного читателя вообще дозваниваются.
 */

beforeEach(function (): void {
    config(['settings.general.filament_admin_emails' => ['owner@intertooler.test']]);

    $this->owner = User::factory()->create(['email' => 'owner@intertooler.test']);
});

it('показывает владельцу ссылку с ключом предпросмотра, а не адрес из текста', function (): void {
    config([
        'ai_support.chat.preview.enabled' => true,
        'ai_support.chat.preview.key' => 'klyuch-1',
        'company.site_url' => 'https://intertooler.ru',
    ]);

    $this->actingAs($this->owner)
        ->get(BotGuide::getUrl())
        ->assertSuccessful()
        ->assertSee('https://intertooler.ru/?bot=klyuch-1')
        // Разделы админки — ссылками: «найдите в меню» этот читатель не осилит.
        ->assertSee(ChatConversationResource::getUrl('index'))
        ->assertSee(KbArticleResource::getUrl('index'));
});

it('после снятия предпросмотра не обещает, что бота видно только владельцу', function (): void {
    config(['ai_support.chat.preview.enabled' => false, 'company.site_url' => 'https://intertooler.ru']);

    $this->actingAs($this->owner)
        ->get(BotGuide::getUrl())
        ->assertSuccessful()
        ->assertSee('Его уже видят покупатели')
        ->assertDontSee('?bot=');
});

it('значок «новое» гаснет первым открытием и больше не возвращается', function (): void {
    expect(BotGuide::getNavigationBadge())->toBe('новое');

    $this->actingAs($this->owner)->get(BotGuide::getUrl())->assertSuccessful();

    expect(Setting::query()->where('key', AssistantConfig::PREFIX.'guide_read_at')->exists())->toBeTrue()
        ->and(BotGuide::getNavigationBadge())->toBeNull();
});

it('не пускает того, кто не сотрудник', function (): void {
    $this->actingAs(User::factory()->create(['email' => 'buyer@example.com']))
        ->get(BotGuide::getUrl())
        ->assertForbidden();
});

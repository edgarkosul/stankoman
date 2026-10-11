<?php

use App\Models\Setting;
use App\Services\Captcha\CaptchaManager;
use App\Shop\Chat\ShopChatCaptcha;
use Siteko\Chatbot\Contracts\SettingsStore;
use Siteko\Chatbot\Kb\TiptapTextExtractor;
use Siteko\Chatbot\Settings\Keys;

/*
 * Швы магазина к пакету бота, у которых нет своего экрана: настройки сайта
 * (часть — из «Настроек» магазина), капча и свои блоки редактора в базе знаний.
 */

it('общее с витриной берёт из настроек магазина, своё — из настроек бота', function (): void {
    Setting::query()->create(['key' => 'general.shop_name', 'value' => '  InterTooler.ru ', 'type' => 'string', 'autoload' => true]);
    Setting::query()->create(['key' => 'company.public_email', 'value' => 'sales@intertooler.test', 'type' => 'string', 'autoload' => true]);
    Setting::query()->create(['key' => 'general.manager_emails', 'value' => json_encode([' Boss@Intertooler.test', 'не почта']), 'type' => 'json', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => 'company.work_schedule'], ['value' => json_encode(['days' => [1 => ['10:00', '19:00']], 'note' => 'В праздники — выходной']), 'type' => 'json', 'autoload' => true]);

    $settings = app(SettingsStore::class);
    $settings->set('assistant.bot_name', 'Ирина');

    // Название уходит в крошки каждого фрагмента базы, а значит в вектор:
    // ровно `general.shop_name` без пробелов по краям, как у старого движка.
    expect($settings->get(Keys::SITE_NAME))->toBe('InterTooler.ru')
        ->and($settings->get(Keys::CONTACT_EMAIL))->toBe('sales@intertooler.test')
        ->and($settings->get(Keys::ESCALATION_EMAILS))->toBe(['boss@intertooler.test'])
        ->and($settings->get(Keys::OPERATORS_SCHEDULE)[1])->toBe(['10:00', '19:00'])
        ->and($settings->get(Keys::OPERATORS_SCHEDULE)[6])->toBeNull()
        ->and($settings->get(Keys::PRIVACY_URL))->toEndWith('/page/privacy')
        ->and($settings->get(Keys::SITE_HOSTS))->toBe('intertooler.ru,www.intertooler.ru')
        ->and($settings->get('assistant.bot_name'))->toBe('Ирина')
        ->and($settings->all())->toHaveKeys([Keys::SITE_NAME, 'assistant.bot_name']);
});

it('видит правку настроек магазина без перезапуска воркера', function (): void {
    // Воркер очереди держит config() с момента старта; настройки сайта
    // обязаны читаться в момент вызова.
    $settings = app(SettingsStore::class);
    $setting = Setting::query()->create(['key' => 'general.shop_name', 'value' => 'Старое имя', 'type' => 'string', 'autoload' => true]);

    expect($settings->get(Keys::SITE_NAME))->toBe('Старое имя');

    $setting->update(['value' => 'Новое имя']);

    expect($settings->get(Keys::SITE_NAME))->toBe('Новое имя');
});

it('не даёт записать через бота то, что правят в «Настройках» магазина', function (): void {
    app(SettingsStore::class)->set(Keys::SITE_NAME, 'Другое имя');
})->throws(LogicException::class);

it('капча чата — та же, что у форм витрины, с настройками виджета', function (): void {
    config([
        'captcha.enabled' => true,
        'captcha.drivers.smartcaptcha.site_key' => 'site-key',
        'captcha.drivers.smartcaptcha.secret_key' => 'secret-key',
        'captcha.drivers.smartcaptcha.invisible' => true,
        'captcha.drivers.smartcaptcha.hide_shield' => true,
        'captcha.drivers.smartcaptcha.language' => 'ru',
    ]);
    app()->forgetInstance(CaptchaManager::class);

    $captcha = new ShopChatCaptcha(app(CaptchaManager::class));

    expect($captcha->enabled())->toBe(app(CaptchaManager::class)->enabled())
        // Пустой токен при включённой капче — отказ, а не исключение.
        ->and($captcha->enabled() ? $captcha->verify(null) : false)->toBeFalse()
        ->and($captcha->widget())->toMatchArray(['invisible' => true, 'hide_shield' => true, 'language' => 'ru']);
});

it('выключенная капча пропускает всех и ключа в страницу не отдаёт', function (): void {
    config(['captcha.enabled' => false]);
    app()->forgetInstance(CaptchaManager::class);

    $captcha = new ShopChatCaptcha(app(CaptchaManager::class));

    expect($captcha->enabled())->toBeFalse()
        ->and($captcha->siteKey())->toBeNull()
        ->and($captcha->verify(null))->toBeTrue();
});

it('свои блоки редактора доходят до базы знаний тем же текстом, что у старого движка', function (): void {
    // Эталон снят старым TiptapTextExtractor intertooler 11.10.2026: любое
    // расхождение пересчитало бы векторы всех страниц с такими блоками.
    $document = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Вступление.']]],
        ['type' => 'customBlock', 'attrs' => ['id' => 'shipment', 'config' => ['heading' => 'Отгрузка станка в Казань', 'link_label' => 'Станок MetalMaster BS-128', 'link_url' => 'https://intertooler.ru/product/bs-128']]],
        ['type' => 'customBlock', 'attrs' => ['id' => 'shipment', 'config' => ['heading' => '', 'link_label' => 'Без ссылки']]],
        ['type' => 'customBlock', 'attrs' => ['id' => 'pdf-link', 'config' => ['link_text' => 'Паспорт', 'source_type' => 'direct_url', 'url' => 'https://example.test/p.pdf']]],
        ['type' => 'customBlock', 'attrs' => ['id' => 'pdf-link', 'config' => ['link_text' => '', 'source_type' => 'file', 'file' => 'nope/missing.pdf', 'url' => '']]],
        ['type' => 'customBlock', 'attrs' => ['id' => 'pdf-link', 'config' => ['link_text' => '', 'source_type' => 'direct_url', 'url' => 'https://example.test/x.pdf']]],
        ['type' => 'customBlock', 'attrs' => ['id' => 'raw-html', 'config' => ['html' => '<p>Строка&nbsp;1</p><div>Строка 2<br>Строка 3</div>  <ul><li>пункт</li></ul>']]],
        ['type' => 'customBlock', 'attrs' => ['id' => 'hero-slider', 'config' => ['x' => 1]]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Конец.']]],
    ]];

    expect(app(TiptapTextExtractor::class)->toText($document))->toBe(
        "Вступление.\n\nОтгрузка станка в Казань\nСтанок MetalMaster BS-128 (https://intertooler.ru/product/bs-128)\n\n"
        ."Без ссылки\n\nПаспорт (https://example.test/p.pdf)\n\nДокумент (https://example.test/x.pdf)\n\n"
        ."Строка\u{00A0}1\nСтрока 2\nСтрока 3\nпункт\n\nКонец."
    );
});

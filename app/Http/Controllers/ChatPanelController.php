<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Shop\Chat\ChatPreviewGate;
use App\Support\Products\DiscountVisibility;
use Illuminate\Http\Request;
use Siteko\Chatbot\Ai\Tools\ToolContext;
use Siteko\Chatbot\Widget\WidgetSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Страница рамки чата: панель пакета siteko/chatbot на своём документе.
 *
 * Кто посетитель, панель узнаёт здесь, при открытии, и до конца жизни рамки
 * не пересчитывает (`#[Locked]`): вошёл в аккаунт посреди разговора —
 * страница перезагрузится, и рамка откроется заново, уже с ценами «для своих».
 */
class ChatPanelController extends Controller
{
    public function __invoke(Request $request, ChatPreviewGate $gate, WidgetSettings $widget): Response
    {
        // Предпросмотр: кому не видна кнопка, тому не открыть и рамку по адресу.
        abort_unless($gate->visibleNow(), 404);

        $user = $request->user();

        $response = response()->view('chat.panel', [
            'siteId' => (int) config('chatbot.site_id'),
            // Рамка и страница — один сайт: и рукопожатие протокола, и адрес
            // страницы посетителя панель сверяет ровно с этим origin.
            'origins' => [$request->getSchemeAndHttpHost()],
            'visitor' => [ToolContext::SEES_MEMBER_PRICES => DiscountVisibility::allowed()],
            'staff' => $user instanceof User && $user->isFilamentAdmin(),
            'userId' => $user?->getKey(),
            'theme' => $widget->theme(),
        ]);

        // Встраивать рамку можно только в свои страницы.
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

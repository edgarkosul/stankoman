<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TestingAccessGate
{
    public function handle(Request $request, Closure $next): Response
    {
        // Во всех окружениях, кроме testing — никак не вмешиваемся
        if (! app()->environment('production')) {
            return $next($request);
        }

        $cookieName = config('app.testing_gate_cookie', 'test_access_token');
        $expected   = (string) config('app.testing_gate_token', '');
        $paramName  = config('app.testing_gate_param', 'tt'); // разовый сеттер через query

        // 1) Пропуск если есть валидная кука/заголовок
        $incoming = (string) ($request->cookie($cookieName) ?? $request->header('X-Test-Token', ''));
        if ($expected !== '' && $incoming !== '' && hash_equals($expected, $incoming)) {
            return $next($request);
        }

        // 2) Разовый вход: ?tt=TOKEN → ставим куку «навсегда» и редиректим на ту же страницу без query
        if ($request->has($paramName)) {
            $token = (string) $request->query($paramName);
            if ($expected !== '' && hash_equals($expected, $token)) {
                return redirect()->to($request->url())
                    ->withCookie(cookie()->forever($cookieName, $token));
            }
        }

        // 3) Иначе режем доступ
        abort(403, 'Testing gate: token cookie required');
    }
}

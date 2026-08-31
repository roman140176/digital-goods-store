<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Простой токен вместо авторизации — ТЗ это прямо разрешает. */
final class AdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('store.admin_token');
        $provided = (string) ($request->header('X-Admin-Token') ?? $request->query('token', ''));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(403, 'Нужен админский токен: ?token=... или заголовок X-Admin-Token.');
        }

        return $next($request);
    }
}

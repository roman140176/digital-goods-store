<?php

use App\Domain\Orders\OrderConflict;
use App\Http\Middleware\AdminToken;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/api/health',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.token' => AdminToken::class,
        ]);

        // Витрина может быть задеплоена отдельно от бэкенда (ТЗ это разрешает),
        // поэтому у API открытый CORS и нет CSRF-токена.
        $middleware->validateCsrfTokens(except: ['api/*', 'admin/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // У API всегда JSON: без этого ошибка валидации, пришедшая без
        // заголовка Accept, уходила редиректом на HTML-витрину.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Контракт ошибки публичных ручек: «не найдено» — это короткий 404
        // с причиной, а не стектрейс Laravel.
        $notFound = fn (Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Ресурс не найден.', 'reason' => 'not_found'], 404)
            : null;

        $exceptions->render(fn (ModelNotFoundException $e, Request $request) => $notFound($request));
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $notFound($request));

        $exceptions->render(fn (OrderConflict $e) => response()->json([
            'message' => $e->getMessage(),
            'reason' => 'order_conflict',
        ], 409));
    })->create();

<?php

use App\Http\Middleware\AdminToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        //
    })->create();

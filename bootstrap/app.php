<?php

use App\Http\Middleware\EnsureFauneFranceBotToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'faune-france.bot' => EnsureFauneFranceBotToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Keep Laravel's default exception rendering.
    })->create();

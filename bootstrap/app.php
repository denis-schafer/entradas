<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tickets.auth' => \App\Http\Middleware\TicketsAuth::class,
            'tickets.staff' => \App\Http\Middleware\TicketsStaffOnly::class,
            'tickets.portal' => \App\Http\Middleware\TicketsPortalAuth::class,
        ]);

        // MercadoPago notifica por POST a /tickets/mp/webhook y redirige por
        // GET a /tickets/mp/callback. Ninguno de los dos lleva token de sesion,
        // asi que quedan exentos de CSRF.
        $middleware->validateCsrfTokens(except: [
            'tickets/mp/webhook',
            'tickets/mp/callback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // La SPA pide todo por fetch, asi que TODAS las rutas tickets/* tienen
        // que contestar JSON, nunca la pagina de error de Laravel ni un 302.
        // Antes la lista eraPartial (tickets-admin y tickets-portal/api) y el
        // login unico se caia de ella: un password corto devolvia un redirect
        // a "/" y el frontend recibia HTML donde esperaba el 422 con los
        // errores por campo.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('tickets*'),
        );
    })->create();
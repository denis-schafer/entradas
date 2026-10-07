<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Autenticacion
    |--------------------------------------------------------------------------
    |
    | Un unico guard. El panel usa la sesion web (que es lo que permite que
    | /broadcasting/auth resuelva el usuario para autorizar el canal
    | 'tickets.admin'). El portal de compra usa un token propio con el
    | middleware TicketsPortalAuth y no pasa por el guard.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
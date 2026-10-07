<?php

/*
|--------------------------------------------------------------------------
| Broadcasting
|--------------------------------------------------------------------------
|
| El websocket es un acelerador: acelera las pantallas, nunca decide. Por eso
| la conexion que importa es Reverb y el backend nunca debe depender de que el
| servidor este arriba (ver App\Services\Realtime, que se come el error del
| broadcast).
|
| La variable se llama BROADCAST_CONNECTION, la de Laravel 11/12. Antes se
| leia BROADCAST_DRIVER, que Laravel ya no usa: con nombre viejo el valor de
| .env se ignoraba por completo y la app caia al driver "pusher" por defecto.
*/

return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', 'localhost'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // El frontend lee estos valores del layout, asi que si el
                // servidor publico no es el mismo que el del navegador, hay
                // que overridingar REVERB_HOST aca.
            ],
        ],

        // Se deja la conexion pusher por si alguien usa un service externo
        // compatible; no es la que usa la app.
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
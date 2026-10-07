<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MercadoPago
    |--------------------------------------------------------------------------
    |
    | Credenciales OAuth de la aplicacion registrada en el panel de desarrolladores
    | de MercadoPago (https://www.mercadopago.com.ar/developers/panel). Son
    | distintas del access_token que se guarda en tickets_configs.mp_access_token:
    | las de aca son para iniciar el flujo "Conectar por OAuth" del panel de
    | administracion; el access_token es lo que vuelve de MP tras la
    | autorizacion y se guarda en la base.
    |
    | redirect_uri tiene que coincidir EXACTAMENTE con el configurado en la app
    | de MercadoPago. Por default usa la URL del sitio + /tickets-admin/config/
    | mp-callback, que es donde la app recibe el codigo de autorizacion.
    */
    'mercadopago' => [
        'client_id' => env('MP_CLIENT_ID'),
        'client_secret' => env('MP_CLIENT_SECRET'),
        'redirect_uri' => env('MP_REDIRECT_URI'),
        /*
        | Si es false, createPreference() no exige HTTPS publico para construir
        | back_urls/notification_url: usa el app.url aunque sea localhost.
        | Pensado para desarrollo local detras de un tunel (ngrok, cloudflared)
        | o para probar el flujo de checkout sin terminar un pago real.
        | En produccion debe quedar en true: MercadoPago rechaza webhooks a
        | hosts que no sean HTTPS publicos.
        */
        'require_public_url' => env('MP_REQUIRE_PUBLIC_URL', true),
    ],

];

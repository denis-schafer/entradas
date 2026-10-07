<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- El tema oscuro del portal y el claro del panel los decide el frontend
         segun que shell monte, no el servidor. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Credenciales de Reverb en base64. Van en la pagina a proposito: son las
         que el navegador necesita para conectarse al websocket, y son las
         publicas del protocolo. El secreto y el id de la app no salen de aca.
         Si Reverb no esta configurado no se emite el meta y el frontend se
         queda solo con polling. --}}
    @php
        // host, port y scheme viven abajo de "options" en config/reverb.php;
        // leerlos en el nivel de la app mandaba null al navegador y el websocket
        // nunca podia connectar.
        $reverbApp = config('reverb.apps.apps.0', []);
        $reverbOptions = $reverbApp['options'] ?? [];
    @endphp
    @if (config('broadcasting.default') === 'reverb' && ($reverbApp['key'] ?? null))
        <meta name="reverb-key" content="{{ base64_encode(json_encode([
            'host' => $reverbOptions['host'] ?? null,
            'port' => (int) ($reverbOptions['port'] ?? 0),
            'scheme' => $reverbOptions['scheme'] ?? 'https',
            'key' => $reverbApp['key'],
        ])) }}">
    @endif
    <title>{{ config('app.name', 'Entradas') }}</title>
    <link rel="icon" href="/favicon.ico">
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
</head>
<body>
    <div id="app">
        {{-- Pantalla de carga previa a montar Vue. Si algo falla al arrancar,
             el usuario ve esto y no una pagina en blanco. --}}
        <div class="boot">
            <div class="boot__mark"></div>
            <p>Cargando…</p>
        </div>
    </div>
</body>
</html>
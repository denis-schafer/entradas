<?php

namespace App\Http\Controllers;

use App\Support\MercadoPagoToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TicketsConfigController extends Controller
{
    /**
     * Configuraciones que nunca se devuelven en claro al navegador.
     *
     * El frontend solo necesita saber si estan cargadas, no su valor. mp_access_token
     * y mp_webhook_secret SI pueden escribirse desde la API (necesario para que el
     * admin pueda pegarlos a mano cuando no hay OAuth configurado), pero al listarlos
     * se devuelven enmascarados como "__set__" para no filtrar el valor por la API.
     *
     * qr_secret queda completamente bloqueado: lo autogenera el sistema en el primer
     * QR firmado, y permitir que un admin lo escriba seria firmar QR falsos.
     */
    private const SECRETS = [
        'qr_secret',
        'mp_access_token',
        'mp_webhook_secret',
    ];

    /**
     * Configs que pueden editarse desde el panel aun siendo "secretos" en el sentido
     * de que nunca se devuelven en claro al navegador. mp_access_token y
     * mp_webhook_secret son editables a mano como alternativa al flujo OAuth.
     */
    private const EDITABLE_SECRETS = [
        'mp_access_token',
        'mp_webhook_secret',
    ];

    public function index(): JsonResponse
    {
        $configs = DB::table('tickets_configs')->orderBy('id')->get()
            ->map(function ($config) {
                if (in_array($config->name, self::SECRETS, true)) {
                    $config->value = $config->value === null || $config->value === '' ? '' : '__set__';
                }

                return $config;
            });

        return response()->json($configs);
    }

    public function update(Request $request): JsonResponse
    {
        $items = $request->input('items', []);

        if (! is_array($items)) {
            return response()->json(['message' => 'Formato invalido'], 422);
        }

        $rejected = [];

        foreach ($items as $item) {
            /*
            | El cliente puede mandar {id, value} para campos visibles (que siempre
            | tienen fila) o {name, value} para secrets editables, donde la fila
            | puede no existir todavia (mp_access_token no se siembra: se crea al
            | pegar el token o al volver de OAuth). Por eso preferimos name cuando
            | viene, y caemos al id solo si no hay name.
            */
            $name = $item['name'] ?? null;
            $id = $item['id'] ?? null;

            if (! $name && ! $id) {
                continue;
            }

            $current = null;

            if ($name) {
                $current = DB::table('tickets_configs')->where('name', $name)->first();
            }

            if (! $current && $id) {
                $current = DB::table('tickets_configs')->where('id', $id)->first();
            }

            /*
            | SECRETS protege el valor de salir en la respuesta. EDITABLE_SECRETS
            | (mp_access_token y mp_webhook_secret) si pueden escribirse: el admin
            | los pega a mano cuando no quiere/puede pasar por OAuth. qr_secret
            | queda totalmente bloqueado: lo genera el primer QR firmado.
            */
            if (! $current) {
                // Auto-create solo para EDITABLE_SECRETS (nadie puede inventarse
                // un qr_secret o cualquier otra clave por esta via).
                if ($name && in_array($name, self::EDITABLE_SECRETS, true)) {
                    DB::table('tickets_configs')->insert([
                        'name' => $name,
                        'value' => $item['value'] ?? '',
                        'type' => 'text',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                continue;
            }

            $isReadOnly = in_array($current->name, self::SECRETS, true)
                && ! in_array($current->name, self::EDITABLE_SECRETS, true);

            if ($isReadOnly) {
                $rejected[] = $current->name;
                continue;
            }

            DB::table('tickets_configs')->where('id', $current->id)->update([
                'value' => $item['value'] ?? '',
                'updated_at' => now(),
            ]);
        }

        $response = ['message' => 'Configuracion actualizada'];

        if ($rejected) {
            $response['secrets_ignorados'] = $rejected;
        }

        return response()->json($response);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:5120',
            'name' => 'required|string|max:100',
        ]);

        $configName = $request->input('name');

        if (in_array($configName, self::SECRETS, true)) {
            return response()->json(['message' => 'Ese campo no acepta imagenes'], 422);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
            return response()->json(['message' => 'Formato no soportado'], 422);
        }

        $filename = 'tickets_'.preg_replace('/[^a-z0-9_]/i', '', $configName).'_'.time().'.'.$ext;
        $dir = public_path('uploads/tickets');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, $filename);
        $url = '/uploads/tickets/'.$filename;

        DB::table('tickets_configs')->where('name', $configName)->update([
            'value' => $url,
            'updated_at' => now(),
        ]);

        return response()->json(['url' => $url, 'message' => 'Imagen subida']);
    }

    public function deleteImage(Request $request): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:100']);

        DB::table('tickets_configs')->where('name', $request->input('name'))->update([
            'value' => '',
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Imagen eliminada']);
    }

    /**
     * URL de autorizacion OAuth de MercadoPago para un evento especifico.
     *
     * El token que devuelve MP se guarda en tickets_events.mp_access_token.
     * event_id llega por query/POST: el operador lo configura al apretar
     * "Conectar MP via OAuth" en la edicion del evento.
     */
    /**
     * URL de autorizacion OAuth de MercadoPago para un evento especifico.
     *
     * El token que devuelve MP se guarda en tickets_events.mp_access_token.
     * event_id viaja por el param `state` del OAuth (preservado por MP en
     * el redirect de regreso): agregarlo a la query del redirect_uri hace
     * que MP lo compare contra la redirect_uri registrada y tire error.
     */
    public function getMpOAuthUrl(Request $request): JsonResponse
    {
        $clientId = config('services.mercadopago.client_id') ?? env('MP_CLIENT_ID');

        if (! $clientId) {
            return response()->json([
                'message' => 'MP_CLIENT_ID no configurado. Defini MP_CLIENT_ID y MP_CLIENT_SECRET en el .env del servidor.',
            ], 500);
        }

        $eventId = (int) $request->input('event_id', $request->query('event_id'));

        if (! $eventId) {
            return response()->json(['message' => 'Falta event_id'], 422);
        }

        $redirectUri = url('/tickets-admin/config/mp-callback');

        return response()->json([
            'url' => 'https://auth.mercadopago.com/authorization?'.http_build_query([
                'client_id' => $clientId,
                'response_type' => 'code',
                'platform_id' => 'mp',
                'redirect_uri' => $redirectUri,
                'state' => 'event:'.$eventId,
            ]),
        ]);
    }

    /**
     * Devuelve si el evento tiene su MP configurada. Solo expone un flag,
     * nunca el token.
     */
    public function mpStatus(Request $request): JsonResponse
    {
        $eventId = (int) $request->input('event_id', $request->query('event_id'));

        $platformToken = MercadoPagoToken::platform();

        if (! $eventId) {
            return response()->json([
                'connected' => false,
                'event_connected' => false,
                'has_platform_token' => $platformToken !== null,
                'uses_platform_account' => $platformToken !== null,
                'has_client_id' => $this->hasClientId(),
            ]);
        }

        $event = DB::table('tickets_events')->where('id', $eventId)->first(['mp_access_token']);
        $eventConnected = ! empty($event?->mp_access_token);

        return response()->json([
            'connected' => $eventConnected,
            'event_connected' => $eventConnected,
            'has_platform_token' => $platformToken !== null,
            'uses_platform_account' => ! $eventConnected && $platformToken !== null,
            'has_client_id' => $this->hasClientId(),
        ]);
    }

    private function hasClientId(): bool
    {
        return ! empty(config('services.mercadopago.client_id') ?? env('MP_CLIENT_ID'));
    }

    /**
     * Verifica que un access_token sirva para llamar a la API de MP.
     * Distingue token invalido/expirado de un error de red.
     *
     * Dos modos:
     *   - token: el operador lo testea ANTES de guardarlo (paste + Probar).
     *   - event_id: prueba el token ya guardado en el evento. Tras el OAuth
     *     el input del frontend queda vacio (el backend enmascara el token
     *     como __set__), asi que sin este modo no hay forma de validar el
     *     token conectado.
     *
     * Va por POST para que el token no quede en la URL ni en los logs.
     */
    public function testMpToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'nullable|string|max:255',
            'event_id' => 'nullable|integer',
        ]);

        $source = 'provided';
        $accessToken = trim((string) ($data['token'] ?? ''));

        if ($accessToken === '') {
            $eventId = (int) ($data['event_id'] ?? 0);

            if (! $eventId) {
                return response()->json(['message' => 'Falta token o event_id'], 422);
            }

            $event = DB::table('tickets_events')->where('id', $eventId)->first(['mp_access_token']);
            $accessToken = trim((string) ($event?->mp_access_token ?? ''));

            if ($accessToken === '') {
                return response()->json(['message' => 'El evento no tiene token guardado'], 422);
            }

            $source = 'stored';
        }

        try {
            // /v1/payment_methods es GET, publico y solo requiere auth valida.
            $response = Http::withToken($accessToken)->timeout(10)->get(
                'https://api.mercadopago.com/v1/payment_methods'
            );

            $body = $response->json();

            if ($response->failed()) {
                $detail = $body['message'] ?? 'MercadoPago respondio '.$response->status();
                $codes = [];

                if (isset($body['cause']) && is_array($body['cause'])) {
                    foreach ($body['cause'] as $c) {
                        if (isset($c['code'])) {
                            $codes[] = $c['code']
                                .(isset($c['description']) ? ': '.$c['description'] : '');
                        }
                    }
                }

                return response()->json([
                    'ok' => false,
                    'status' => $response->status(),
                    'message' => $detail.(empty($codes) ? '' : ' | '.implode(' | ', $codes)),
                    'token_prefix' => substr($accessToken, 0, 12).'...',
                    'source' => $source,
                ]);
            }

            return response()->json([
                'ok' => true,
                'message' => 'Token operativo. MP respondio '.$response->status().'.',
                'token_prefix' => substr($accessToken, 0, 12).'...',
                'source' => $source,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error de red: '.$e->getMessage(),
                'token_prefix' => substr($accessToken, 0, 12).'...',
                'source' => $source,
            ]);
        }
    }
}
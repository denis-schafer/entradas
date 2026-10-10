<?php

namespace App\Http\Controllers;

use App\Services\OrderPaymentService;
use App\Services\Payments\MercadoPagoGateway;
use App\Services\Realtime;
use App\Support\MercadoPagoToken;
use App\Support\QrPayload;
use App\Support\QrRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TicketsMercadoPagoController extends Controller
{
    /**
     * Crea la preferencia de Checkout Pro para una orden pendiente.
     *
     * El pref_id se guarda en la orden: es lo que permite que el webhook
     * resuelva el pago de forma idempotente y sin depender de parsear strings.
     */
    public function createPreference(Request $request, ?int $orderId = null): JsonResponse
    {
        $buyer = $request->attributes->get('tickets_portal_user');

        if (! $buyer) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $orderId = $orderId ?? (is_numeric($request->input('order_id')) ? (int) $request->input('order_id') : null);

        if (! $orderId) {
            return response()->json(['message' => 'order_id invalido'], 422);
        }

        $order = DB::table('tickets_orders')
            ->where('id', $orderId)
            ->where('buyer_user_id', $buyer['id'])
            ->where('status', 'pending')
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada o ya pagada'], 404);
        }

        /*
        | El access_token es per-evento: el operador (o el organizador del
        | evento) lo carga manualmente o via OAuth en la edicion del evento.
        | Si el evento no tiene token propio, se cobra con la cuenta de la
        | plataforma (tickets_configs.mp_access_token); solo si ninguna de las
        | dos esta configurada no hay a quien cobrarle al comprador.
        */
        $event = DB::table('tickets_events')->where('id', $order->event_id)->first();

        if (! $event) {
            return response()->json(['message' => 'Evento no disponible'], 404);
        }

        $mpMethod = DB::table('tickets_payment_methods')->where('code', 'mercadopago')->first();

        if (! $mpMethod || (int) $mpMethod->enabled !== 1) {
            return response()->json([
                'message' => 'Este evento no acepta pagos con MercadoPago por el momento.',
            ], 400);
        }

        $accessToken = MercadoPagoToken::forEvent($event);

        if (empty($accessToken)) {
            return response()->json([
                'message' => 'Este evento no tiene MercadoPago configurado. Pedile al organizador que cargue su Access token (o conecte via OAuth) en la edicion del evento.',
            ], 400);
        }

/*
 * $config carga solo las keys que siguen siendo globales (redirect_uri):
 * redirect_uri es la URL publica del sitio y la usa el backend para armar
 * back_urls y notification_url de la preference. mp_access_token ya no va
 * aca porque es per-evento.
 */
$config = DB::table('tickets_configs')->pluck('value', 'name')->all();

// MercadoPago exige URLs publicas con https en back_urls y
// notification_url. Nunca url(), que devuelve http://localhost.
$publicBase = $this->publicBase($config);

        if ($publicBase === null) {
            $redirectValue = trim((string) ($config['redirect_uri'] ?? ''));
            $redirectDisplay = $redirectValue === '' ? 'vacio' : $redirectValue;

            return response()->json([
                'message' => 'MercadoPago requiere una URL publica con HTTPS para devolver al comprador. '
                    .'Configura "URL de retorno de MercadoPago" (redirect_uri) en Tickets > Configuracion '
                    .'con una URL publica (ej. https://tickets.salfest.com.ar) o publicalo con un tunel '
                    .'(ngrok, cloudflared). Valor actual: '.$redirectDisplay.'. '
                    .'Para desarrollo local detras de tunel, tambien podes setear MP_REQUIRE_PUBLIC_URL=false en .env.',
            ], 422);
        }

        $ticketsCount = DB::table('tickets_tickets')->where('order_id', $order->id)->count();

        // Limite de cuotas segun los tipos de entrada de la orden. Sin esto
        // MercadoPago usa su default y ofrece hasta 24.
        $types = DB::table('tickets_tickets as tk')
            ->join('tickets_event_ticket_types as tt', 'tk.ticket_type_id', '=', 'tt.id')
            ->where('tk.order_id', $order->id)
            ->distinct()
            ->get(['tt.payment_mode', 'tt.max_installments']);

        $onlySingle = $types->isNotEmpty() && $types->every(fn ($t) => $t->payment_mode === 'single');
        $typeMax = $onlySingle ? 1 : max(1, (int) ($types->min('max_installments') ?? 1));

        // La modalidad ya quedo fijada al crear la orden y el importe a cobrar
        // es order->total. Si eligio cuotas se ofrecen como maximo las que
        // eligio (el total NO cambia: MP suma su interes encima).
        $maxInstallments = ($order->payment_mode ?? 'single') === 'installments'
            ? min((int) ($order->installment_count ?? 1), $typeMax)
            : 1;
        $maxInstallments = max(1, min($maxInstallments, 24));

/*
| auto_return: 'approved' requiere que back_urls.success sea HTTPS. En
| desarrollo local con HTTP (entradas.test) MP rechaza la preference. Lo
| dejamos solo cuando la URL es HTTPS publica: en ese caso el comprador
| vuelve solo a nuestro sitio. Bajo HTTP el comprador vuelve a mano desde
| la pantalla de MP (que sigue funcionando, es solo menos comodo).
*/
$payload = [
    'items' => [[
        'id' => (string) $order->id,
        'title' => 'Entrada(s) - '.($event->name ?? 'Evento'),
        'description' => "Compra de {$ticketsCount} entrada(s)",
        'quantity' => 1,
        'unit_price' => (float) $order->total,
        'currency_id' => 'ARS',
    ]],
    'external_reference' => 'ENTRADAS-TICKET-'.$order->id,
    'notification_url' => $publicBase.'/tickets/mp/webhook/'.(int) $event->id,
    'back_urls' => [
        'success' => $publicBase.'/tickets/mp/callback',
        'failure' => $publicBase.'/tickets/mp/callback',
        'pending' => $publicBase.'/tickets/mp/callback',
    ],
    // OJO: el campo es payment_methods (plural). Con el singular MercadoPago
    // lo ignora en silencio y ofrece hasta 24 cuotas.
    'payment_methods' => [
        'installments' => $maxInstallments,
        'default_installments' => $maxInstallments,
    ],
];

if (str_starts_with($publicBase, 'https://')) {
    $payload['auto_return'] = 'approved';
}

try {
    $response = Http::withToken($accessToken)->timeout(20)->post(
        'https://api.mercadopago.com/checkout/preferences',
        $payload
    );

            if ($response->failed()) {
                $body = $response->json();
                $detail = $body['message'] ?? $response->body();

                // MercadoPago manda un array `cause` con {code, description} por
                // cada policy que fallo. Extraemos los utiles para el operador.
                if (isset($body['cause']) && is_array($body['cause'])) {
                    $codes = [];

                    foreach ($body['cause'] as $c) {
                        if (isset($c['code'])) {
                            $codes[] = $c['code']
                                .(isset($c['description']) ? ': '.$c['description'] : '');
                        }
                    }

                    if ($codes) {
                        $detail .= ' | '.implode(' | ', $codes);
                    }
                }

                /*
                | 401 / 403 suele ser token invalido, expirado, o del tipo
                | equivocado (Client ID / Secret en lugar de Access Token, o
                | token de sandbox contra la API de produccion). Lo aclaramos
                | porque el mensaje generico de "At least one policy returned
                | UNAUTHORIZED" no dice nada al operador.
                */
                $status = $response->status();
                $authHint = '';

                if (in_array($status, [401, 403], true)) {
                    $token = $accessToken ?? '';

                    if (! preg_match('/^(APP_USR-|TEST-|TEST-)/', $token)) {
                        $authHint = ' El valor guardado no parece un Access Token de MercadoPago (deberia empezar con APP_USR-, TEST- o TEST_USR-): probablemente estas pegando el Client ID o el Client Secret. ';
                    } else {
                        $authHint = ' El Access Token es invalido, expiro, o pertenece al ambiente equivocado (sandbox vs produccion). ';
                    }
                }

                Log::error('[TicketsMP] Preference creation failed', [
                    'status' => $status,
                    'detail' => $detail,
                    'order_id' => $order->id,
                ]);

                return response()->json(['message' => 'Error al crear preference: '.$detail.$authHint], 400);
            }

            $pref = $response->json();

            DB::table('tickets_orders')->where('id', $order->id)->update([
                'payment_reference' => $pref['id'] ?? null,
                'payment_method' => 'mercadopago',
                'updated_at' => now(),
            ]);

            return response()->json([
                'preference_id' => $pref['id'] ?? null,
                'init_point' => $pref['init_point'] ?? null,
                'sandbox_init_point' => $pref['sandbox_init_point'] ?? null,
                'installments' => $maxInstallments,
            ]);
        } catch (\Throwable $e) {
            Log::error('[TicketsMP] createPreference exception', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
            ]);

            return response()->json(['message' => 'Error: '.$e->getMessage()], 500);
        }
    }

    /**
     * Destino del comprador cuando vuelve de MercadoPago (back_urls).
     *
     * MercadoPago redirige aca con toda la query del pago (payment_id,
     * status, external_reference...). La app no usa vue-router: ese query
     * seria ruido en la barra del comprador y ademas queda registrado en los
     * logs del proxy, asi que se registra todo en el log del servidor y se
     * redirige a la raiz pelada, sin query y sin fragment.
     *
     * La orden NO viaja en la URL. El frontend la resuelve solo: guardó el
     * order_id y el public_token en sessionStorage antes de abrir el checkout,
     * y sessionStorage sobrevive el ida y vuelta a mercadopago.com en la misma
     * pestana. Por eso aca no hace falta pasarle nada.
     */
    public function callback(Request $request): RedirectResponse
    {
        // MercadoPago devuelve preference_id y order_id como parametros de
        // query. Se resuelven igual para poder distinguir "volvio por una orden
        // mia" de "volvio por algo que no existe", pero el resultado no sale
        // en la redireccion.
        $token = null;

        if ($prefId = $request->query('preference_id')) {
            $token = DB::table('tickets_orders')->where('payment_reference', $prefId)->value('public_token');
        }

        if (! $token && ($mpOrderId = $request->query('order_id')) && is_numeric($mpOrderId)) {
            $token = DB::table('tickets_orders')
                ->where('payment_reference', DB::table('tickets_orders')
                    ->where('id', (int) $mpOrderId)->value('payment_reference'))
                ->value('public_token');
        }

        $base = $this->publicBase(DB::table('tickets_configs')->pluck('value', 'name')->all())
            ?? rtrim((string) config('app.url'), '/');

        // Se registra el retorno en el log del servidor, que si sabe guardar
        // trazabilidad de pagos sin ensuciar la barra de direcciones.
        Log::info('Retorno de MercadoPago', [
            'preference_id' => $request->query('preference_id'),
            'order_id' => $request->query('order_id'),
            'status' => $request->query('status'),
            'orden_localizada' => $token !== null,
            'order_id_resuelto' => $token ? DB::table('tickets_orders')->where('public_token', $token)->value('id') : null,
        ]);

        return redirect()->away(rtrim($base, '/').'/');
    }

    /**
     * Webhook de MercadoPago. Directo: no hay cola ni agente intermedio.
     *
     * Idempotente por diseño: si la orden ya esta pagada con el mismo
     * payment_id se responde ok y no se toca nada, porque MercadoPago reintenta
     * la entrega y el navegador tambien puede disparar el retorno.
     */
    public function webhook(Request $request, int $event_id): JsonResponse
    {
        $payload = $request->json()->all() ?: $request->all();
        $paymentId = $payload['data']['id'] ?? null;

        if (! $paymentId || ! is_numeric($paymentId)) {
            return response()->json(['status' => 'ok']);
        }

        /*
        | event_id viene en la URL: cada preference se creo con notification_url
        | apuntando a tickets/mp/webhook/{event_id}, asi MP ya nos dice a que
        | evento pertenece este pago antes de poder llamar a la API. El token
        | es per-evento (cargado en la edicion del evento, manual u OAuth).
        */
        $event = DB::table('tickets_events')->where('id', $event_id)->first();

        if (! $event) {
            Log::warning('[TicketsMP Webhook] evento desconocido', [
                'event_id' => $event_id,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['status' => 'ok']);
        }

        // Token del evento o, si no tiene, el de la cuenta de la plataforma.
        $accessToken = MercadoPagoToken::forEvent($event);

        if (empty($accessToken)) {
            Log::warning('[TicketsMP Webhook] evento y plataforma sin access_token, pago ignorado', [
                'event_id' => $event_id,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['status' => 'ok']);
        }

        /*
        | El webhook secret queda en la config global (es la firma que la
        | plataforma registra en MP, no algo del organizador).
        */
        if (! $this->signatureIsValid($request, (string) $paymentId)) {
            Log::warning('[TicketsMP Webhook] firma invalida, pago ignorado', [
                'payment_id' => $paymentId,
            ]);

            return response()->json(['status' => 'invalid signature'], 403);
        }

        try {
            // merchant_order llega sin data.id: se resuelve contra la API.
            if ($request->input('type') === 'merchant_order') {
                $moId = $payload['resource'] ?? $payload['id'] ?? null;

                if (is_string($moId) && preg_match('#/(\d+)$#', $moId, $m)) {
                    $moId = $m[1];
                }

                if ($moId) {
                    $mo = Http::withToken($accessToken)->timeout(15)
                        ->get("https://api.mercadolibre.com/merchant_orders/{$moId}");

                    $paymentId = $mo->successful() ? ($mo->json()['payments'][0]['id'] ?? null) : null;

                    if (! $paymentId) {
                        return response()->json(['status' => 'ok']);
                    }
                }
            }

            $resp = Http::withToken($accessToken)->timeout(15)
                ->get("https://api.mercadopago.com/v1/payments/{$paymentId}");

            if ($resp->failed()) {
                Log::warning('[TicketsMP Webhook] no se pudo leer el pago', [
                    'event_id' => $event_id,
                    'payment_id' => $paymentId,
                    'status' => $resp->status(),
                ]);

                return response()->json(['status' => 'ok']);
            }

            $payment = $resp->json();

            if (($payment['status'] ?? '') !== 'approved') {
                Log::info('[TicketsMP Webhook] pago no aprobado, sin cambios', [
                    'event_id' => $event_id,
                    'payment_id' => $paymentId,
                    'status' => $payment['status'] ?? null,
                ]);

                return response()->json(['status' => 'ok']);
            }

            $order = $this->resolveOrder($payment);

            if (! $order) {
                Log::warning('[TicketsMP Webhook] external_reference desconocida', [
                    'payment_id' => $paymentId,
                    'external_reference' => $payment['external_reference'] ?? null,
                ]);

                return response()->json(['status' => 'ok']);
            }

            app(OrderPaymentService::class)->markPaid($order, $paymentId, $payment, 'mercadopago');

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            Log::error('[TicketsMP Webhook] exception', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId,
            ]);

            // 500 para que MercadoPago reintente: perder un pago confirmado es
            // peor que duplicar la entrega, y el camino es idempotente.
            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Guarda el token de la orden que se acaba de pagar en la preferencia, para
     * que el callback pueda devolver al comprador sin adivinar ids en la URL.
     */
    public function orderToken(Request $request, int $orderId): JsonResponse
    {
        $buyer = $request->attributes->get('tickets_portal_user');

        if (! $buyer) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $order = DB::table('tickets_orders')
            ->where('id', $orderId)
            ->where('buyer_user_id', $buyer['id'])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        return response()->json(['public_token' => $order->public_token]);
    }

    /**
     * SVG del QR de un boleto, para reimprimir desde el panel y para que el
     * comprador vea su entrada en el celu. El <img> apunta aca directo.
     */
    public function qrSvg(Request $request, int $ticketId)
    {
        $request->validate(['size' => 'nullable|integer|min:120|max:1024']);

        $ticket = DB::table('tickets_tickets')->where('id', $ticketId)->first();

        if (! $ticket || empty($ticket->qr_payload)) {
            abort(404);
        }

        return response(QrRenderer::svg($ticket->qr_payload, (int) $request->query('size', 400)), 200, [
            'Content-Type' => 'image/svg+xml',
            // El QR es la prueba de entrada: no debe quedar en una cache compartida.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function oauthCallback(Request $request)
    {
        $code = $request->query('code');
        $state = (string) $request->query('state', '');

        if (! $code) {
            return response()->json(['message' => 'Falta code'], 400);
        }

        /*
        | event_id viaja por `state` (estandar OAuth RFC 6749), porque MP
        | preserva state en el redirect de regreso pero puede ignorar otros
        | params. Formato esperado: "event:<id>".
        */
        $eventId = 0;

        if (preg_match('/^event:(\d+)$/', $state, $m)) {
            $eventId = (int) $m[1];
        } else {
            // Fallback: si por algun motivo el state no viene (ej: operador
            // configurado a mano en MP sin state), caemos al query.
            $eventId = (int) $request->query('event_id');
        }

        if (! $eventId) {
            return response()->json(['message' => 'Falta event_id'], 400);
        }

        $gateway = new MercadoPagoGateway;
        $clientId = $gateway->clientId();
        $clientSecret = $gateway->clientSecret();
        $redirectUri = url('/tickets-admin/config/mp-callback');

        try {
            $resp = Http::asForm()->timeout(20)->post('https://api.mercadopago.com/oauth/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]);

            if ($resp->failed()) {
                return response()->json([
                    'message' => 'Error al obtener token',
                    'detail' => $resp->json(),
                ], 400);
            }

            $body = $resp->json();

            /*
            | El token se guarda en la config del evento (pivote de medios de
            | pago): cada operador conecta su MP al evento que organiza, y los
            | pagos de ese evento caen ahi.
            */
            MercadoPagoToken::storeForEvent($eventId, $body['access_token'] ?? null);

            // MP redirige al callback del navegador, no a un cliente API: si
            // devolvemos JSON el operador ve una pagina cruda fuera de la app.
            // Redirigimos a la raiz (la SPA ya tiene la sesion de admin).
            return redirect('/');
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error: '.$e->getMessage()], 500);
        }
    }

    /**
     * Orden a la que pertenece un pago, por external_reference y, como red de
     * seguridad, por pref_id persistido.
     */
    private function resolveOrder(array $payment)
    {
        $ref = (string) ($payment['external_reference'] ?? '');

        if (preg_match('/^ENTRADAS-TICKET-(\d+)$/', $ref, $m)) {
            return DB::table('tickets_orders')->where('id', (int) $m[1])->first();
        }

        $prefId = $payment['preference_id'] ?? null;

        if ($prefId) {
            return DB::table('tickets_orders')->where('payment_reference', $prefId)->first();
        }

        return null;
    }

    /**
     * Verifica la firma que MercadoPago manda en x-signature.
     *
     * Solo se exige si hay mp_webhook_secret configurado: sin ese secreto no
     * hay nada contra lo que comparar, y rejecting todos los pagos seria peor
     * que aceptar el riesgo documentado.
     */
    private function signatureIsValid(Request $request, string $paymentId): bool
    {
        $secret = DB::table('tickets_configs')->where('name', 'mp_webhook_secret')->value('value');

        if (empty($secret)) {
            return true;
        }

        $header = (string) $request->header('x-signature', '');
        $requestId = (string) $request->header('x-request-id', '');

        parse_str(str_replace(['ts=', ','], ['&', '&'], $header), $parts);
        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;

        if (! $ts || ! $v1) {
            return false;
        }

        $manifest = "id:{$paymentId};request-id:{$requestId};ts:{$ts};";

        return hash_equals(hash_hmac('sha256', $manifest, $secret), (string) $v1);
    }

    /**
     * Base publica para back_urls y notification_url, sin ruta.
     * Null cuando no hay una URL https alcanzable, para avisar en vez de
     * mandar a MercadoPago un localhost.
     *
     * Si MP_REQUIRE_PUBLIC_URL=false (modo dev), devuelve el app.url aunque
     * sea localhost: util para probar el checkout detras de un tunel o para
     * validar la UI sin terminar un pago real.
     */
    private function publicBase(array $config): ?string
    {
        $requirePublic = (bool) config('services.mercadopago.require_public_url', true);

        $candidate = trim((string) ($config['redirect_uri'] ?? ''));

        if ($candidate === '') {
            $candidate = rtrim((string) config('app.url'), '/');
        } else {
            $parts = parse_url($candidate);
            $path = preg_replace('#/(tickets/mp/(callback|webhook))?$#', '', $parts['path'] ?? '');
            $candidate = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
                .(isset($parts['port']) ? ':'.$parts['port'] : '')
                .$path;
        }

        $candidate = rtrim($candidate, '/');

        // Modo dev: no exigimos HTTPS publico. MP igual rechazara los webhooks
        // cuando los envie, pero al menos podemos probar el checkout de UI.
        if (! $requirePublic) {
            return $candidate !== '' ? $candidate : null;
        }

        $isHttps = (bool) preg_match('#^https://#i', $candidate);
        $isLocal = (bool) preg_match('#^(https?://)?(localhost|127\.0\.0\.1|entradas\.test|erden\.test)(:\d+)?#i', $candidate);

        return ($isHttps && ! $isLocal) ? $candidate : null;
    }

    private function putConfig(string $name, ?string $value, string $type, ?string $current = null): string
    {
        $value = $current ?: $value;

        DB::table('tickets_configs')->updateOrInsert(
            ['name' => $name],
            ['value' => $value, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]
        );

        return (string) $value;
    }
}
<?php

namespace App\Services\Payments;

use App\Support\MercadoPagoToken;
use App\Support\OrderWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Creacion de preferencias de Checkout Pro de MercadoPago.
 *
 * Vive en un servicio (y no en el controlador del portal) porque el POS
 * tambien cobra con MP: el vendedor genera la misma preferencia para mandarle
 * el link de pago al comprador. Lo unico particular de cada uno es la
 * respuesta HTTP y de donde sale la orden.
 */
class MercadoPagoCheckout
{
    private const API = 'https://api.mercadopago.com/checkout/preferences';

    /**
     * Preferencia de una orden pendiente. Con $reuse intenta recuperar la que
     * ya estaba guardada (reenvio del link); si no sirve, crea una nueva.
     *
     * @return array{preference_id:?string,init_point:?string,sandbox_init_point:?string,installments:?int}
     *
     * @throws PaymentSetupException
     */
    public function preferenceForOrder(object $order, bool $reuse = false): array
    {
        $this->assertPayable($order);

        if ($reuse && ! empty($order->payment_reference)) {
            $existing = $this->fetchPreference($order, (string) $order->payment_reference);

            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->createPreferenceForOrder($order);
    }

    /**
     * @return array{preference_id:?string,init_point:?string,sandbox_init_point:?string,installments:int}
     *
     * @throws PaymentSetupException
     */
    public function createPreferenceForOrder(object $order): array
    {
        $this->assertPayable($order);

        $event = DB::table('tickets_events')->where('id', $order->event_id)->first();

        if (! $event) {
            throw new PaymentSetupException('Evento no disponible', 404);
        }

        $gateway = PaymentGatewayRegistry::for('mercadopago');

        if (! $gateway || ! $gateway->eventEnabled((int) $event->id)) {
            throw new PaymentSetupException('Este evento no acepta pagos con MercadoPago por el momento.', 400);
        }

        $accessToken = MercadoPagoToken::forEvent($event);

        if (empty($accessToken)) {
            throw new PaymentSetupException(
                'Este evento no tiene MercadoPago configurado. Pedile al organizador que cargue su Access token (o conecte via OAuth) en la edicion del evento.',
                400
            );
        }

        /*
        | $config carga solo las keys que siguen siendo globales (redirect_uri):
        | redirect_uri es la URL publica del sitio y la usa el backend para armar
        | back_urls y notification_url de la preference.
        */
        $config = DB::table('tickets_configs')->pluck('value', 'name')->all();
        $publicBase = self::publicBase($config);

        if ($publicBase === null) {
            $redirectValue = trim((string) ($config['redirect_uri'] ?? ''));
            $redirectDisplay = $redirectValue === '' ? 'vacio' : $redirectValue;

            throw new PaymentSetupException(
                'MercadoPago requiere una URL publica con HTTPS para devolver al comprador. '
                    .'Configura "URL de retorno de MercadoPago" (redirect_uri) en Tickets > Configuracion '
                    .'con una URL publica (ej. https://tickets.salfest.com.ar) o publicalo con un tunel '
                    .'(ngrok, cloudflared). Valor actual: '.$redirectDisplay.'. '
                    .'Para desarrollo local detras de tunel, tambien podes setear MP_REQUIRE_PUBLIC_URL=false en .env.',
                422
            );
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
            // OJO: el campo es payment_methods (plural). Con el singular
            // MercadoPago lo ignora en silencio y ofrece hasta 24 cuotas.
            'payment_methods' => [
                'installments' => $maxInstallments,
                'default_installments' => $maxInstallments,
            ],
        ];

        if (str_starts_with($publicBase, 'https://')) {
            $payload['auto_return'] = 'approved';
        }

        /*
        | La preferencia vence junto con la orden: al final del dia en que se
        | creo o al cerrar la venta del tipo, lo que ocurra primero. Asi el
        | comprador no puede pagar un precio viejo al dia siguiente.
        */
        $deadline = OrderWindow::deadline($order);
        $payload['expires'] = true;
        $payload['expiration_date_from'] = CarbonImmutable::now()->toIso8601String();
        $payload['expiration_date_to'] = $deadline->toIso8601String();

        $response = Http::withToken($accessToken)->timeout(20)->post(self::API, $payload);

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
                if (! preg_match('/^(APP_USR-|TEST-|TEST-)/', (string) $accessToken)) {
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

            throw new PaymentSetupException('Error al crear preference: '.$detail.$authHint, 400);
        }

        $pref = $response->json();

        DB::table('tickets_orders')->where('id', $order->id)->update([
            'payment_reference' => $pref['id'] ?? null,
            'payment_method' => 'mercadopago',
            'updated_at' => now(),
        ]);

        return [
            'preference_id' => $pref['id'] ?? null,
            'init_point' => $pref['init_point'] ?? null,
            'sandbox_init_point' => $pref['sandbox_init_point'] ?? null,
            'installments' => $maxInstallments,
        ];
    }

    /**
     * Recupera una preferencia existente por su id. Null si ya no sirve.
     */
    private function fetchPreference(object $order, string $preferenceId): ?array
    {
        $event = DB::table('tickets_events')->where('id', $order->event_id)->first();
        $accessToken = $event ? MercadoPagoToken::forEvent($event) : null;

        if (empty($accessToken)) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)->timeout(15)->get(self::API.'/'.$preferenceId);
        } catch (\Throwable $e) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $pref = $response->json();

        if (empty($pref['init_point']) && empty($pref['sandbox_init_point'])) {
            return null;
        }

        return [
            'preference_id' => $pref['id'] ?? $preferenceId,
            'init_point' => $pref['init_point'] ?? null,
            'sandbox_init_point' => $pref['sandbox_init_point'] ?? null,
            'installments' => null,
        ];
    }

    /**
     * La orden tiene que seguir pendiente, dentro de su dia y con el precio
     * vigente. Si no, no se genera una preferencia nueva.
     */
    private function assertPayable(object $order): void
    {
        if (! OrderWindow::isPayable($order)) {
            throw new PaymentSetupException(
                'La reserva venció o el precio de las entradas cambió. Iniciá una compra nueva.',
                410,
            );
        }
    }

    /**
     * Base publica para back_urls y notification_url, sin ruta.
     *
     * Si MP_REQUIRE_PUBLIC_URL=false (modo dev), devuelve el app.url aunque
     * sea localhost: util para probar el checkout detras de un tunel o para
     * validar la UI sin terminar un pago real.
     */
    public static function publicBase(array $config): ?string
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
}

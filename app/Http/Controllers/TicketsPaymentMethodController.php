<?php

namespace App\Http\Controllers;

use App\Services\OrderPaymentService;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| Administracion del modulo "Medios de pago" (solo staff).
|
| Para cada medio (MercadoPago, Multipago...) expone:
|   - la config GLOBAL del proveedor (credenciales de la cuenta), enmascarada.
|   - los eventos y su habilitado/config por evento.
|
| Las credenciales van enmascaradas al frontend; guardar un campo con el valor
| enmascarado ('••••••••') o vacio significa "no tocar". Para borrar una clave
| hay que mandarla en "cleared_keys".
*/
class TicketsPaymentMethodController extends Controller
{
    private const MASK = '••••••••';

    public function index(): JsonResponse
    {
        $events = DB::table('tickets_events')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $result = [];

        foreach (PaymentGatewayRegistry::all() as $gateway) {
            $row = $gateway->methodRow();

            $methodConfig = $row
                ? $gateway->maskConfig(json_decode((string) ($row->config ?? '[]'), true) ?: [])
                : [];

            $eventRows = [];

            foreach ($events as $event) {
                $pivot = $gateway->eventPivot((int) $event->id);

                $eventRows[] = [
                    'event_id' => $event->id,
                    'event_name' => $event->name,
                    'slug' => $event->slug,
                    'enabled' => $pivot ? (int) $pivot->enabled === 1 : true,
                    'has_pivot' => $pivot !== null,
                    'config' => $pivot
                        ? $gateway->maskConfig(json_decode((string) ($pivot->config ?? '[]'), true) ?: [])
                        : [],
                ];
            }

            $result[] = [
                'code' => $gateway->code(),
                'name' => $gateway->name(),
                'enabled' => $row !== null && (int) $row->enabled === 1,
                'config' => $methodConfig,
                'secret_keys' => $gateway->secretKeys(),
                'mask' => self::MASK,
                'webhook_url' => method_exists($gateway, 'webhookUrl') ? $gateway->webhookUrl() : null,
                'events' => $eventRows,
            ];
        }

        return response()->json(['methods' => $result]);
    }

    /**
     * Guarda la config global de un medio (enabled + config).
     */
    public function update(Request $request, string $code): JsonResponse
    {
        $gateway = $this->gatewayOrFail($code);
        $row = $gateway->methodRow();

        if (! $row) {
            return response()->json(['message' => 'Medio de pago no existe'], 404);
        }

        $current = json_decode((string) ($row->config ?? '[]'), true) ?: [];
        $incoming = $request->input('config', []);
        $cleared = $request->input('cleared_keys', []);

        $config = $this->mergeSafe($current, $incoming, $cleared);

        DB::table('tickets_payment_methods')->where('id', $row->id)->update([
            'enabled' => $request->has('enabled') ? (int) $request->boolean('enabled') : $row->enabled,
            'config' => json_encode($config),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Config guardada']);
    }

    /**
     * Guarda el estado/config de un medio para UN evento.
     */
    public function updateEvent(Request $request, string $code, int $eventId): JsonResponse
    {
        $gateway = $this->gatewayOrFail($code);
        $methodId = $gateway->methodId();

        if (! $methodId) {
            return response()->json(['message' => 'Medio de pago no existe'], 404);
        }

        $eventExists = DB::table('tickets_events')->where('id', $eventId)->exists();

        if (! $eventExists) {
            return response()->json(['message' => 'Evento no existe'], 404);
        }

        $pivot = $gateway->eventPivot($eventId);
        $current = $pivot ? (json_decode((string) ($pivot->config ?? '[]'), true) ?: []) : [];
        $incoming = $request->input('config', []);
        $cleared = $request->input('cleared_keys', []);

        $config = $this->mergeSafe($current, $incoming, $cleared);
        $enabled = $request->has('enabled') ? $request->boolean('enabled') : ($pivot ? (int) $pivot->enabled === 1 : true);

        DB::table('tickets_event_payment_method')->updateOrInsert(
            ['event_id' => $eventId, 'payment_method_id' => $methodId],
            [
                'enabled' => $enabled ? 1 : 0,
                'config' => json_encode($config),
                'updated_at' => now(),
            ]
        );

        return response()->json(['message' => 'Config del evento guardada']);
    }

    /**
     * Prueba de conexion del medio con las credenciales guardadas.
     */
    public function test(Request $request, string $code): JsonResponse
    {
        $gateway = $this->gatewayOrFail($code);

        if ($gateway->code() === 'multipago') {
            return $this->testMultipago($gateway);
        }

        $eventId = $request->integer('event_id') ?: null;

        // Permite probar un token recien tipeado, sin guardarlo.
        $explicit = $request->input('access_token');
        $explicit = is_string($explicit) ? trim($explicit) : '';

        if ($explicit === '' || $explicit === self::MASK) {
            $explicit = null;
        }

        return $this->testMercadoPago($gateway, $eventId, $explicit);
    }

    /**
     * "Validar pagos": consulta la API de Multipago y procesa los cobros
     * pendientes (mismo camino que el webhook, pero a demanda).
     */
    public function validatePayments(Request $request, string $code): JsonResponse
    {
        $gateway = $this->gatewayOrFail($code);

        if ($gateway->code() !== 'multipago') {
            return response()->json(['message' => 'Validacion disponible solo para Multipago'], 422);
        }

        $lock = Cache::lock('multipago-validate', 300);

        if (! $lock->get()) {
            return response()->json(['message' => 'Ya hay una validacion en curso'], 409);
        }

        try {
            $data = $gateway->consultCobros(
                $request->input('desde'),
                $request->input('hasta')
            );

            if ($data['status'] !== 'SUCCESS') {
                return response()->json([
                    'message' => 'Multipago respondio '.$data['status'].'. Revisa las credenciales.',
                ], 422);
            }

            $summary = ['insertadas' => 0, 'duplicadas' => 0, 'sin_procesar' => 0, 'total' => 0];

            foreach ($data['cobros'] as $cobro) {
                $summary['total']++;

                $result = $this->processMultipagoCobro($cobro);

                $summary[$result]++;
            }

            return response()->json([
                'message' => 'Validacion terminada',
                'summary' => $summary,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * URL del webhook unico del medio (Multipago: una sola cuenta/URL).
     */
    public function webhookUrl(string $code): JsonResponse
    {
        $gateway = $this->gatewayOrFail($code);
        $url = method_exists($gateway, 'webhookUrl') ? $gateway->webhookUrl() : '';

        return response()->json(['url' => $url]);
    }

    /**
     * Rota la key del webhook unico (invalida la URL anterior).
     */
    public function regenerateWebhookKey(string $code): JsonResponse
    {
        $gateway = $this->gatewayOrFail($code);

        if (! method_exists($gateway, 'regenerateWebhookKey')) {
            return response()->json(['message' => 'El medio no usa webhook'], 422);
        }

        return response()->json(['url' => $gateway->regenerateWebhookKey()]);
    }

    // ----------------------------------------------------------------- helpers

    private function gatewayOrFail(string $code): PaymentGateway
    {
        $gateway = PaymentGatewayRegistry::for($code);

        if (! $gateway) {
            abort(404, 'Medio de pago no existe');
        }

        return $gateway;
    }

    /**
     * Mezcla config nueva sobre la actual sin pisar secrets enmascarados.
     */
    private function mergeSafe(array $current, array $incoming, array $cleared): array
    {
        foreach ($cleared as $key) {
            unset($current[$key]);
        }

        foreach ($incoming as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if ($value === self::MASK || $value === '') {
                continue;
            }

            $current[$key] = $value;
        }

        return $current;
    }

    private function testMercadoPago($gateway, ?int $eventId = null, ?string $explicit = null): JsonResponse
    {
        if ($explicit !== null) {
            $token = $explicit;
            $source = $eventId ? 'el token ingresado (sin guardar)' : 'el access_token ingresado';
        } elseif ($eventId) {
            $own = method_exists($gateway, 'ownEventToken') ? $gateway->ownEventToken($eventId) : null;
            $token = $own ?? (method_exists($gateway, 'platformToken') ? $gateway->platformToken() : null);
            $source = $own !== null
                ? 'token OAuth del evento'
                : 'cuenta de la plataforma (el evento no tiene token propio)';
        } else {
            $token = method_exists($gateway, 'platformToken') ? $gateway->platformToken() : null;
            $source = 'cuenta de la plataforma';
        }

        if (empty($token)) {
            return response()->json([
                'ok' => false,
                'message' => 'No hay access_token guardado para '.$source.'.',
            ], 422);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($token)->timeout(15)
                ->get('https://api.mercadopago.com/users/me');

            if ($response->successful()) {
                $id = $response->json('id');
                $nickname = $response->json('nickname');

                return response()->json([
                    'ok' => true,
                    'message' => 'Conexión OK ('.$source.': MP user '.$id.' @'.$nickname.').',
                ]);
            }

            return response()->json([
                'ok' => false,
                'message' => 'MP respondió '.$response->status().' con '.$source.'. Revisá el access_token.',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Error: '.$e->getMessage()], 500);
        }
    }

    private function testMultipago($gateway): JsonResponse
    {
        if (trim((string) $gateway->methodConfig()['bersacode'] ?? '') === ''
            || trim((string) $gateway->methodConfig()['username'] ?? '') === ''
            || trim((string) $gateway->methodConfig()['password'] ?? '') === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Faltan credenciales de Multipago (bersacode, usuario y contraseña).',
            ], 422);
        }

        $cobros = $gateway->consultCobros(now()->toDateString(), now()->toDateString());

        if ($cobros['status'] === 'SUCCESS') {
            return response()->json([
                'ok' => true,
                'message' => 'Conexion OK con Multipago.',
            ]);
        }

        return response()->json([
            'ok' => false,
            'message' => 'Multipago respondio '.$cobros['status'].'. Revisa las credenciales.',
        ], 422);
    }

    /**
     * Procesa un cobro de Multipago (comun a webhook y validacion).
     *
     * @return string insertadas | duplicadas | sin_procesar
     */
    private function processMultipagoCobro(array $cobro): string
    {
        $multipago = PaymentGatewayRegistry::for('multipago');

        $normalized = $multipago->normalizeWebhook($cobro);

        if (! $normalized['ok']) {
            return 'sin_procesar';
        }

        $order = DB::table('tickets_orders')->where('id', $normalized['order_id'])->first();

        if (! $order) {
            return 'sin_procesar';
        }

        if (($normalized['amount'] ?? null) !== null) {
            $orderTotal = (float) $order->total;
            $diff = abs($orderTotal - $normalized['amount']);

            if ($diff > 0.01) {
                return 'sin_procesar';
            }
        }

        $result = app(OrderPaymentService::class)->markPaid(
            $order,
            (string) $normalized['payment_id'],
            ['transaction_amount' => $normalized['amount']],
            'multipago'
        );

        return $result['changed'] ? 'insertadas' : 'duplicadas';
    }
}
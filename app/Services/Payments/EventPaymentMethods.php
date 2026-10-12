<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;

/**
 * Que medios de pago se pueden cobrar en un evento, ya resueltos con sus
 * credenciales y las variantes de cada uno.
 *
 * Es la unica fuente de verdad del "que se puede cobrar": la usa el checkout
 * del portal, el POS (para saber que puede ofrecer un vendedor) y el panel de
 * usuarios (para ofrecer los medios asignables). Antes vivia dentro de
 * TicketsCheckoutController::methods().
 *
 * El efectivo NO aparece aca: se cobra siempre y no depende de configuracion.
 */
class EventPaymentMethods
{
    /**
     * Medios habilitados y cobrables para un evento, con sus variantes.
     *
     * @return array<int,array{code:string,variant:?string,name:string,type:string,subtitle:string}>
     */
    public static function forEvent(int $eventId): array
    {
        $methods = [];

        foreach (PaymentGatewayRegistry::all() as $gateway) {
            if (! $gateway->eventEnabled($eventId)) {
                continue;
            }

            if ($gateway->code() === 'mercadopago') {
                // Solo se ofrece si ademas hay con que cobrar.
                if (empty($gateway->eventToken($eventId))) {
                    continue;
                }

                $methods[] = [
                    'code' => 'mercadopago',
                    'variant' => null,
                    'name' => $gateway->name(),
                    'type' => 'redirect',
                    'subtitle' => 'Tarjeta, Rapipago, Pago Facil',
                ];

                continue;
            }

            if ($gateway->code() === 'multipago') {
                // Multipago expone dos modalidades independientes: QR y boton
                // de pago. Cada evento habilita o deshabilita cada una.
                //
                // Credenciales: el evento puede tener cuenta propia; si le
                // faltan, usa la global. Para ofrecer el medio alcanza con
                // bersacode + usuario (la contrasena se pide recien al validar).
                $bersacode = $gateway instanceof MultipagoGateway
                    ? $gateway->bersacodeForEvent($eventId)
                    : '';
                $username = $gateway instanceof MultipagoGateway
                    ? $gateway->usernameForEvent($eventId)
                    : '';

                if ($bersacode === '' || $username === '') {
                    continue;
                }

                // El QR nativo ademas necesita el header y la semilla del
                // convenio (el evento puede tener los suyos). Sin ellos solo
                // queda ofrecible el boton de pago.
                $qrReady = $gateway instanceof MultipagoGateway && $gateway->qrReady($eventId);

                if ($qrReady && $gateway->qrEnabled($eventId)) {
                    $methods[] = [
                        'code' => 'multipago',
                        'variant' => 'qr',
                        'name' => 'Multipago (QR)',
                        'type' => 'multipago-qr',
                        'subtitle' => 'Escanea el QR con cualquier billetera virtual',
                    ];
                }

                if ($gateway->buttonEnabled($eventId)) {
                    $methods[] = [
                        'code' => 'multipago',
                        'variant' => 'button',
                        'name' => 'Multipago (Boton de pago)',
                        'type' => 'multipago-button',
                        'subtitle' => 'Codigo de barras para pagar en Multipago',
                    ];
                }
            }
        }

        return $methods;
    }

    /**
     * Codes distintos disponibles en un evento (mercadopago, multipago...).
     *
     * @return string[]
     */
    public static function codesForEvent(int $eventId): array
    {
        return array_values(array_unique(array_map(
            fn ($method) => $method['code'],
            self::forEvent($eventId)
        )));
    }

    /**
     * Codes distintos disponibles en la union de varios eventos.
     *
     * @param  array<int,int|string>  $eventIds
     * @return string[]
     */
    public static function codesForEvents(array $eventIds): array
    {
        $codes = [];

        foreach (array_unique(array_map('intval', $eventIds)) as $eventId) {
            foreach (self::codesForEvent($eventId) as $code) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Medios asignables (con id de la tabla) disponibles en un conjunto de
     * eventos. Multipago aparece una sola vez aunque exponga qr y boton.
     *
     * @param  array<int,int|string>  $eventIds
     * @return array<int,array{id:int,code:string,name:string}>
     */
    public static function forEvents(array $eventIds): array
    {
        $codes = self::codesForEvents($eventIds);

        if (! $codes) {
            return [];
        }

        return DB::table('tickets_payment_methods')
            ->where('enabled', 1)
            ->whereIn('code', $codes)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name'])
            ->map(fn ($method) => [
                'id' => (int) $method->id,
                'code' => $method->code,
                'name' => $method->name,
            ])
            ->all();
    }

    /**
     * IDs de los medios disponibles en un conjunto de eventos.
     *
     * @param  array<int,int|string>  $eventIds
     * @return int[]
     */
    public static function idsForEvents(array $eventIds): array
    {
        return array_map(fn ($method) => $method['id'], self::forEvents($eventIds));
    }
}

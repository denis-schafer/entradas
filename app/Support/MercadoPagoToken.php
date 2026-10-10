<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resuelve con que access_token de MercadoPago se cobra un evento.
 *
 * Modelo de credenciales (modulo "Medios de pago", ver tabla
 * tickets_payment_methods y tickets_event_payment_method):
 *   - La cuenta de la plataforma vive en la config del medio mercadopago
 *     (config.platform_access_token). Antes era tickets_configs.mp_access_token;
 *     esa fila se migro en 2026_10_07_000023 y aca queda como fallback
 *     compatible por si alguien la sigue cargando a mano.
 *   - Cada evento puede conectarse con su propia cuenta via OAuth; el token se
 *     guarda en el pivote tickets_event_payment_method (config.access_token).
 *   - Sin token propio se cobra con la cuenta de la plataforma.
 *
 * Cualquier lugar que necesite cobrar/leer un pago de MP debe pasar por aca
 * para que el fallback sea uno solo y no se dupliquen reglas.
 */
final class MercadoPagoToken
{
    private const METHOD_CODE = 'mercadopago';

    /**
     * Fila (pivote) de la config del evento para el medio MP, o null.
     */
    private static function pivotForEvent(int $eventId): ?object
    {
        return DB::table('tickets_event_payment_method as ep')
            ->join('tickets_payment_methods as pm', 'pm.id', '=', 'ep.payment_method_id')
            ->where('ep.event_id', $eventId)
            ->where('pm.code', self::METHOD_CODE)
            ->first(['ep.enabled', 'ep.config']);
    }

    private static function methodRow(): ?object
    {
        return DB::table('tickets_payment_methods')
            ->where('code', self::METHOD_CODE)
            ->first(['enabled', 'config']);
    }

    /**
     * Token de la cuenta de la plataforma, o null si no esta configurado.
     */
    public static function platform(): ?string
    {
        $method = self::methodRow();

        if ($method) {
            $config = json_decode((string) ($method->config ?? '[]'), true) ?: [];
            $token = self::clean($config['platform_access_token'] ?? null);

            if ($token !== null) {
                return $token;
            }
        }

        // Compatibilidad: fila vieja de tickets_configs (migrada y rellenada
        // varias veces a mano).
        $legacy = DB::table('tickets_configs')->where('name', 'mp_access_token')->value('value');

        return self::clean($legacy);
    }

    /**
     * Token con el que se cobra el evento: el del evento si existe, si no el
     * de la plataforma.
     *
     * @param  object|null  $event  fila de tickets_events
     */
    public static function forEvent(?object $event): ?string
    {
        if ($event && ! empty($event->id)) {
            $pivot = self::pivotForEvent((int) $event->id);

            if ($pivot && (int) $pivot->enabled === 1) {
                $config = json_decode((string) ($pivot->config ?? '[]'), true) ?: [];
                $token = self::clean($config['access_token'] ?? null);

                if ($token !== null) {
                    return $token;
                }
            }
        }

        return self::platform();
    }

    /**
     * Token propio del evento (el del pivote), SIN caer a la plataforma.
     * Null si el evento no tiene cuenta propia conectada.
     */
    public static function ownEventToken(int $eventId): ?string
    {
        $pivot = self::pivotForEvent($eventId);

        if (! $pivot) {
            return null;
        }

        $config = json_decode((string) ($pivot->config ?? '[]'), true) ?: [];

        return self::clean($config['access_token'] ?? null);
    }

    /**
     * El medio MP esta activado globalmente y el evento tiene token (propio o
     * de plataforma).
     */
    public static function canCharge(int $eventId): bool
    {
        $method = self::methodRow();

        if (! $method || (int) $method->enabled !== 1) {
            return false;
        }

        return self::forEvent(DB::table('tickets_events')->where('id', $eventId)->first()) !== null;
    }

    /**
     * Guarda (o borra, si $token es null/vacio) el token propio de un evento.
     */
    public static function storeForEvent(int $eventId, ?string $token): void
    {
        $method = self::methodRow();

        if (! $method) {
            return;
        }

        $token = self::clean($token);

        if ($token === null) {
            DB::table('tickets_event_payment_method')
                ->where('event_id', $eventId)
                ->where('payment_method_id', $method->id)
                ->delete();

            return;
        }

        DB::table('tickets_event_payment_method')->updateOrInsert(
            ['event_id' => $eventId, 'payment_method_id' => $method->id],
            [
                'enabled' => true,
                'config' => json_encode(['access_token' => $token]),
                'updated_at' => now(),
            ]
        );
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
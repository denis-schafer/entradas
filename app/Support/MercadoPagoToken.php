<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resuelve con que access_token de MercadoPago se cobra un evento.
 *
 * Modelo de credenciales: cada evento puede conectarse a su propia cuenta de
 * MP (tickets_events.mp_access_token, via OAuth o paste manual). Si el evento
 * NO tiene token propio, se cobra con la cuenta de la plataforma
 * (tickets_configs.mp_access_token), que es la del operador del sistema.
 *
 * Cualquier lugar que necesite cobrar/leer un pago de MP debe pasar por aca
 * para que el fallback sea uno solo y no se dupliquen reglas.
 */
final class MercadoPagoToken
{
    /**
     * Token de la cuenta de la plataforma, o null si no esta configurado.
     */
    public static function platform(): ?string
    {
        $value = DB::table('tickets_configs')->where('name', 'mp_access_token')->value('value');

        return self::clean($value);
    }

    /**
     * Token con el que se cobra el evento: el del evento si existe, si no el
     * de la plataforma.
     *
     * @param  object|null  $event  fila de tickets_events
     */
    public static function forEvent(?object $event): ?string
    {
        $token = self::clean($event->mp_access_token ?? null);

        return $token ?? self::platform();
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}

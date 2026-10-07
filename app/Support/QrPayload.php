<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Formato del QR de un boleto.
 *
 * El QR lleva el uuid del boleto y una firma HMAC-SHA256 del uuid con el
 * qr_secret de la configuracion. El escaner recalcula la firma y compara con
 * hash_equals, asi un QR con el uuid cambiado no pasa.
 *
 * El generate y el scan tienen que usar EXACTAMENTE el mismo formato, por eso
 * viven aca y no duplicados en cada controlador.
 */
class QrPayload
{
    public static function make(string $uuid, string $secret): string
    {
        return (string) json_encode([
            'uuid' => $uuid,
            's' => hash_hmac('sha256', $uuid, $secret),
        ]);
    }

    /**
     * Devuelve el qr_secret de la configuracion, creandolo si todavia no
     * existe.
     *
     * Existe como metodo aca y no en cada controlador porque tiene que ser el
     * MISMO secreto para emitir y para escanear, y porque el que lo crea tiene
     * que ser el primero que lo usa. Si dos pedidos simultaneos llegan sin
     * secreto, insertOrIgnore resuelve el empate: uno gana y el otro lee el
     * ganador, asi que nunca quedan dos secretos distintos en circulacion.
     *
     * Sin esto, el primer QR de una instalacion nueva se firmaria con cadena
     * vacia y despues el escaner lo rechazaria por firma invalida.
     */
    public static function secret(): string
    {
        $existing = DB::table('tickets_configs')->where('name', 'qr_secret')->value('value');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $secret = Str::random(64);

        // insertOrIgnore + indice unico en name: si dos pedidos simultaneos
        // llegan sin secreto, solo uno inserta y el otro lee al ganador. Asi
        // nunca quedan dos secretos distintos firmando QR al mismo tiempo.
        DB::table('tickets_configs')->insertOrIgnore([
            'name' => 'qr_secret',
            'value' => $secret,
            'type' => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Se relee porque otra peticion concurrente pudo insertar primero.
        return (string) DB::table('tickets_configs')->where('name', 'qr_secret')->value('value');
    }

    /**
     * @return array{uuid:string,s:string}|null  null si el JSON no tiene la
     *         forma esperada o si la firma no coincide con el secreto.
     */
    public static function verify(string $raw, string $secret): ?array
    {
        $payload = json_decode($raw, true);

        if (! is_array($payload) || empty($payload['uuid']) || empty($payload['s'])) {
            return null;
        }

        $expected = hash_hmac('sha256', $payload['uuid'], $secret);

        if (! hash_equals($expected, (string) $payload['s'])) {
            return null;
        }

        return [
            'uuid' => (string) $payload['uuid'],
            's' => (string) $payload['s'],
        ];
    }
}
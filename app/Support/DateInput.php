<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Normaliza las fechas que manda el navegador a lo que MySQL acepta.
 *
 * El frontend usa <input type="datetime-local"> y convierte con
 * Date.toISOString(), asi que lo que llega por el body es algo como
 *
 *     2026-10-30T23:00:00.000Z
 *
 * La regla `date` de Laravel lo acepta (es ISO 8601 valido) y por eso la
 * validacion pasaba, pero las columnas de estas tablas son `datetime` peladas y
 * MySQL no parsea ni la "Z" ni los milisegundos:
 *
 *     SQLSTATE[22007]: Invalid datetime format ... Incorrect datetime value
 *
 * Este helper hace dos cosas, en este orden:
 *
 *   1. Convierte de UTC a la zona de la app. No es cosmetico: si solo se
 *      cortaba la "Z", un evento de las 20:00 se guardaba a las 23:00.
 *      Carbon ya parsea la "Z" como UTC, asi que un ->setTimezone() alcanza.
 *   2. Formatea a "Y-m-d H:i:s", que es lo que la columna quiere.
 *
 * Un valor ya plano ("2026-10-30 20:00:00" o "2026-10-30T20:00") se interpreta
 * como hora local, que es lo que corresponde cuando alguien carga datos por SQL
 * o por un importador.
 */
final class DateInput
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function normalize(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $data[$key] = self::one($data[$key]);
        }

        return $data;
    }

    /**
     * @return string|null
     */
    public static function one(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $parsed = CarbonImmutable::parse($value);

        return $parsed->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
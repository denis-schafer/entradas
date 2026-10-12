<?php

namespace App\Support;

/**
 * Filtro por medio de pago para los listados del panel.
 *
 * El frontend manda un solo valor que puede incluir la modalidad de Multipago
 * separada por dos puntos:
 *
 *   efectivo            -> payment_method = 'efectivo'
 *   mercadopago         -> payment_method = 'mercadopago'
 *   multipago           -> payment_method = 'multipago' (QR y boton)
 *   multipago:qr        -> payment_method = 'multipago' y variante qr
 *   multipago:button    -> payment_method = 'multipago' y variante button
 *
 * Las ordenes viejas de Multipago no tienen variante (quedan en NULL): se
 * tratan como QR, igual que en el resto del sistema.
 */
class PaymentMethodFilter
{
    public const CODES = ['efectivo', 'mercadopago', 'multipago'];

    public const VARIANTS = ['qr', 'button'];

    /**
     * Normaliza el valor recibido. Devuelve null cuando no hay que filtrar
     * (vacio o desconocido), para no dejar la lista en blanco por un typo.
     *
     * @return array{code:string,variant:?string}|null
     */
    public static function normalize($value): ?array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        [$code, $variant] = array_pad(explode(':', $value, 2), 2, null);

        if (! in_array($code, self::CODES, true)) {
            return null;
        }

        // Solo Multipago expone modalidades; en el resto la variante no aplica.
        if ($code !== 'multipago' || ! in_array($variant, self::VARIANTS, true)) {
            $variant = null;
        }

        return ['code' => $code, 'variant' => $variant];
    }

    /**
     * Aplica el filtro ya normalizado a una query (o a un JoinClause, que
     * comparte la misma API de where/whereNull).
     *
     * @param  array{code:string,variant:?string}|null  $parsed
     */
    public static function constrain($query, ?array $parsed, string $methodColumn, string $variantColumn): void
    {
        if ($parsed === null) {
            return;
        }

        $query->where($methodColumn, $parsed['code']);

        if ($parsed['code'] !== 'multipago' || $parsed['variant'] === null) {
            return;
        }

        if ($parsed['variant'] === 'qr') {
            // Sin variante cuenta como QR (ordenes previas a la columna).
            $query->where(function ($q) use ($variantColumn) {
                $q->whereNull($variantColumn)->orWhere($variantColumn, 'qr');
            });

            return;
        }

        $query->where($variantColumn, 'button');
    }

    /**
     * Normaliza y aplica en un solo paso.
     */
    public static function apply($query, $value, string $methodColumn, string $variantColumn): void
    {
        self::constrain($query, self::normalize($value), $methodColumn, $variantColumn);
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Formato del numero de entrada.
 *
 * El correlativo se guarda como entero (sirve para ordenar y para los rangos del
 * sorteo) y el evento define desde que numero arranca y con cuantos digitos se
 * muestra. Este helper concentra ese formato para que el QR, el comprobante del
 * POS, el proyector del sorteo y los listados muestren siempre lo mismo.
 */
final class TicketNumber
{
    /**
     * Deja el numero con el ancho configurado, rellenando con ceros a la
     * izquierda cuando hace falta. Un numero mas largo que el ancho no se corta.
     */
    public static function format(?int $number, ?int $pad): ?string
    {
        if ($number === null) {
            return null;
        }

        $value = (string) $number;
        $pad = (int) $pad;

        return $pad > strlen($value) ? str_pad($value, $pad, '0', STR_PAD_LEFT) : $value;
    }

    /**
     * Ancho con ceros configurado en el evento (1 equivale a "sin ceros").
     */
    public static function padForEvent(?int $eventId): int
    {
        if (! $eventId) {
            return 1;
        }

        return max(1, (int) (DB::table('tickets_events')->where('id', $eventId)->value('ticket_number_pad') ?? 1));
    }

    /**
     * Texto "N° ..." que va debajo del QR, ya con el ancho del evento.
     */
    public static function caption(?int $number, ?int $eventId = null): ?string
    {
        if ($number === null) {
            return null;
        }

        return 'N° '.self::format($number, self::padForEvent($eventId));
    }
}

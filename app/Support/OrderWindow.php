<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Vigencia de una orden pendiente.
 *
 * Una orden se puede pagar hasta el menor entre:
 *   - el final del dia en que se creo (23:59:59 en la zona de la app), y
 *   - el fin de venta del tipo de entrada mas restrictivo de la orden.
 *
 * Ademas, para que el pago sea valido, el precio actual de los tipos tiene que
 * seguir coincidiendo con el total guardado en la orden y los tipos/evento
 * tienen que seguir disponibles. Asi una orden vieja no se paga con un precio
 * que ya cambio.
 */
final class OrderWindow
{
    /**
     * Momento exacto hasta el que se puede pagar la orden.
     */
    public static function deadline(object $order): CarbonImmutable
    {
        $deadline = CarbonImmutable::parse((string) $order->created_at)->endOfDay();

        $saleEnd = DB::table('tickets_tickets as t')
            ->join('tickets_event_ticket_types as tt', 't.ticket_type_id', '=', 'tt.id')
            ->where('t.order_id', $order->id)
            ->whereNotNull('tt.sale_end_at')
            ->min('tt.sale_end_at');

        if ($saleEnd !== null) {
            $saleEndAt = CarbonImmutable::parse((string) $saleEnd);

            if ($saleEndAt->lessThan($deadline)) {
                $deadline = $saleEndAt;
            }
        }

        return $deadline;
    }

    public static function isPastDeadline(object $order, ?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return $at->greaterThan(self::deadline($order));
    }

    /**
     * Total que costaria la orden hoy, con los precios actuales de sus tipos.
     */
    public static function currentTotal(object $order): float
    {
        $rows = DB::table('tickets_tickets as t')
            ->join('tickets_event_ticket_types as tt', 't.ticket_type_id', '=', 'tt.id')
            ->where('t.order_id', $order->id)
            ->select('tt.price', DB::raw('COUNT(*) as qty'))
            ->groupBy('t.ticket_type_id', 'tt.price')
            ->get();

        $total = 0.0;

        foreach ($rows as $row) {
            $total += ((float) $row->price) * ((int) $row->qty);
        }

        return round($total, 2);
    }

    public static function priceMatches(object $order): bool
    {
        return abs(self::currentTotal($order) - (float) $order->total) <= 0.01;
    }

    /**
     * Los tipos de la orden siguen existiendo, habilitados, dentro de su
     * ventana de venta y el evento sigue publicado.
     */
    public static function typesAreAvailable(object $order): bool
    {
        $event = DB::table('tickets_events')
            ->where('id', $order->event_id)
            ->first(['status']);

        if (! $event || $event->status !== 'published') {
            return false;
        }

        $types = DB::table('tickets_tickets as t')
            ->join('tickets_event_ticket_types as tt', 't.ticket_type_id', '=', 'tt.id')
            ->where('t.order_id', $order->id)
            ->distinct()
            ->get(['tt.enable', 'tt.sale_start_at', 'tt.sale_end_at']);

        if ($types->isEmpty()) {
            return false;
        }

        $now = CarbonImmutable::now();

        foreach ($types as $type) {
            if (! $type->enable) {
                return false;
            }

            if ($type->sale_start_at !== null
                && CarbonImmutable::parse((string) $type->sale_start_at)->greaterThan($now)) {
                return false;
            }

            if ($type->sale_end_at !== null
                && CarbonImmutable::parse((string) $type->sale_end_at)->lessThan($now)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Si la orden se puede pagar en este momento.
     */
    public static function isPayable(object $order, ?CarbonImmutable $at = null): bool
    {
        if (($order->status ?? null) !== 'pending') {
            return false;
        }

        return ! self::isPastDeadline($order, $at)
            && self::typesAreAvailable($order)
            && self::priceMatches($order);
    }
}

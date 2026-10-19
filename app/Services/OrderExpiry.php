<?php

namespace App\Services;

use App\Support\OrderWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vence las ordenes pendientes que ya no se pueden pagar.
 *
 * Se invoca de dos maneras:
 *   - por orden, desde los endpoints que consultan o intentan pagar, para que
 *     el vencimiento sea inmediato aunque el cron todavia no haya corrido;
 *   - en lote, desde el comando programado, para liberar el stock.
 *
 * Una orden se vence cuando paso el final del dia en que se creo, cuando
 * termino la ventana de venta de alguno de sus tipos o cuando el evento dejo de
 * estar publicado / el tipo se deshabilito.
 */
class OrderExpiry
{
    public function expireIfPastDue(object $order): bool
    {
        if (($order->status ?? null) !== 'pending') {
            return false;
        }

        if (! OrderWindow::isPastDeadline($order) && OrderWindow::typesAreAvailable($order)) {
            return false;
        }

        return $this->expire($order, 'vencida', fn (object $locked) => OrderWindow::isPastDeadline($locked)
            || ! OrderWindow::typesAreAvailable($locked));
    }

    /**
     * La orden tenia un precio y ahora los tipos valen otra cosa: no se puede
     * pagar el total viejo. Se vence para que el comprador compre de nuevo.
     */
    public function expireForPriceChange(object $order): bool
    {
        if (($order->status ?? null) !== 'pending') {
            return false;
        }

        if (OrderWindow::priceMatches($order)) {
            return false;
        }

        return $this->expire(
            $order,
            'cambio de precio',
            fn (object $locked) => ! OrderWindow::priceMatches($locked),
        );
    }

    /**
     * Recorre las pendientes y vence las que ya no se pueden pagar.
     */
    public function expireAllPastDue(): int
    {
        $count = 0;

        DB::table('tickets_orders')
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    if ($this->expireIfPastDue($order)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * @param  callable(object):bool  $shouldExpire
     */
    private function expire(object $order, string $reason, callable $shouldExpire): bool
    {
        $expired = DB::transaction(function () use ($order, $shouldExpire) {
            $locked = DB::table('tickets_orders')
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== 'pending') {
                return null;
            }

            // Se revalida con la fila bloqueada: el comprador pudo pagar entre
            // el chequeo de afuera y este punto.
            if (! $shouldExpire($locked)) {
                return null;
            }

            $perType = DB::table('tickets_tickets')
                ->where('order_id', $locked->id)
                ->where('status', 'valid')
                ->select('ticket_type_id', DB::raw('COUNT(*) as qty'))
                ->groupBy('ticket_type_id')
                ->get();

            DB::table('tickets_tickets')
                ->where('order_id', $locked->id)
                ->where('status', 'valid')
                ->update(['status' => 'expired', 'updated_at' => now()]);

            foreach ($perType as $row) {
                DB::table('tickets_event_ticket_types')
                    ->where('id', $row->ticket_type_id)
                    ->update([
                        'sold_count' => DB::raw('GREATEST(sold_count - '.(int) $row->qty.', 0)'),
                        'updated_at' => now(),
                    ]);
            }

            DB::table('tickets_orders')->where('id', $locked->id)->update([
                'status' => 'expired',
                'payment_reference' => null,
                'updated_at' => now(),
            ]);

            return [
                'id' => (int) $locked->id,
                'event_id' => (int) $locked->event_id,
                'public_token' => (string) $locked->public_token,
                'total' => (string) $locked->total,
                'types' => $perType,
            ];
        });

        if ($expired === null) {
            return false;
        }

        Log::info('[OrderExpiry] orden vencida', [
            'order_id' => $expired['id'],
            'reason' => $reason,
        ]);

        Realtime::orderStatus($expired['id'], 'expired', $expired['total']);
        Realtime::orderStatusForBuyer($expired['public_token'], $expired['id'], 'expired');

        $rows = DB::table('tickets_event_ticket_types')
            ->whereIn('id', $expired['types']->pluck('ticket_type_id'))
            ->get(['id', 'event_id', 'stock', 'sold_count', 'enable']);

        if ($rows->isNotEmpty()) {
            Realtime::stockForTypes(
                (int) $rows->first()->event_id,
                $rows->map(fn ($r) => (array) $r)->all()
            );
        }

        return true;
    }
}

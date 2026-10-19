<?php

namespace App\Services;

use App\Support\OrderWindow;
use App\Support\QrPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
| Logica de "marcar orden como pagada" reutilizada por:
|   - Webhook de MercadoPago (camino normal, cuando MP puede llamar al server).
|   - Webhook de Multipago.
|   - Reconciliacion manual desde el panel admin (cuando los webhooks no
|     llegaron: entorno de desarrollo detras de localhost, deploy nuevo, MP
|     con problemas de entrega, etc.).
|   - Venta en efectivo del POS.
|
| La condicion de idempotencia se conserva: si la orden ya esta paid con ese
| payment_id se sale sin tocar nada; si esta paid con otro payment_id se loguea
| y tambien se sale.
|
| Ademas se valida la vigencia: una orden solo se acredita si el pago fue
| aprobado antes del deadline (final del dia de la orden o fin de venta del
| tipo) y si el precio actual sigue coincidiendo con el total guardado. Un pago
| que el proveedor aprobo antes del deadline pero cuyo aviso llego despues se
| acepta; uno aprobado despues queda para revision/reembolso manual.
*/
class OrderPaymentService
{
    /**
     * Marca la orden como pagada. Idempotente.
     *
     * Devuelve un resumen de lo que paso, util para el endpoint de
     * reconciliacion (donde queremos saber si la accion tuvo efecto o no).
     *
     * @param  object  $order       Orden leida antes (puede tener status stale).
     * @param  string  $paymentId   ID del pago en el proveedor.
     * @param  array   $payment     Payload completo del pago devuelto por el medio.
     * @param  string  $paymentMethod  Code del medio que acredito el pago.
     * @param  mixed   $approvedAt  Fecha en que el proveedor aprobo el pago.
     * @return array{changed: bool, reason: ?string, was_cancelled: bool}
     */
    public function markPaid(
        object $order,
        string $paymentId,
        array $payment,
        string $paymentMethod = 'mercadopago',
        mixed $approvedAt = null,
    ): array {
        $result = [
            'changed' => false,
            'reason' => null,
            'was_cancelled' => false,
        ];

        if ($order->status === 'paid') {
            if ((string) $order->payment_external_id === $paymentId) {
                $result['reason'] = 'already_paid_same_payment';

                return $result;
            }

            Log::error('[OrderPayment] orden ya pagada con otro pago', [
                'order_id' => $order->id,
                'stored_payment_id' => $order->payment_external_id,
                'incoming_payment_id' => $paymentId,
            ]);

            $result['reason'] = 'already_paid_different_payment';

            return $result;
        }

        $paidAt = ($approvedAt !== null && $approvedAt !== '')
            ? CarbonImmutable::parse((string) $approvedAt)
            : CarbonImmutable::now();

        if ($paidAt->greaterThan(OrderWindow::deadline($order))) {
            Log::critical('[OrderPayment] pago fuera de la vigencia', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'approved_at' => $paidAt->toIso8601String(),
                'deadline' => OrderWindow::deadline($order)->toIso8601String(),
            ]);

            $result['reason'] = 'outside_window';

            return $result;
        }

        if (! OrderWindow::priceMatches($order)) {
            Log::critical('[OrderPayment] precio desactualizado', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'stored_total' => $order->total,
                'current_total' => OrderWindow::currentTotal($order),
            ]);

            $result['reason'] = 'price_changed';

            return $result;
        }

        $wasCancelled = false;
        $paidNow = false;
        $skipReason = null;

        DB::transaction(function () use (
            $order, $paymentId, $payment, $paymentMethod, $paidAt, &$wasCancelled, &$paidNow, &$skipReason
        ) {
            /*
            | El lock sobre la orden y el re-chequeo del status van juntos. El
            | webhook de MercadoPago puede llegar dos veces (o dos pagos de la
            | misma orden), y sin esto los dos hilos pasan el if de arriba y
            | reactivan el stock dos veces.
            */
            $locked = DB::table('tickets_orders')->where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                $skipReason = 'not_found';

                return;
            }

            if ($locked->status === 'paid') {
                // Reentrega: se sale sin tocar nada.
                return;
            }

            /*
            | Si la orden ya estaba vencida o cancelada, el pago solo vale si el
            | proveedor lo aprobo antes de que la orden se cerrara. Para una
            | orden vencida por el cambio de dia, updated_at es el momento del
            | vencimiento; para una cancelacion manual, el momento de cancelar.
            */
            $closedAt = in_array($locked->status, ['cancelled', 'expired'], true)
                ? CarbonImmutable::parse((string) $locked->updated_at)
                : null;

            if ($closedAt !== null && $paidAt->greaterThan($closedAt)) {
                $skipReason = 'outside_window';

                return;
            }

            if ($paidAt->greaterThan(OrderWindow::deadline($locked))) {
                $skipReason = 'outside_window';

                return;
            }

            if (! OrderWindow::priceMatches($locked)) {
                $skipReason = 'price_changed';

                return;
            }

            $wasCancelled = $closedAt !== null;

            if ($wasCancelled) {
                /*
                | Se restauran las entradas cerradas (vencidas o canceladas),
                | no todas las de la orden: si el panel anulo una entrada suelta
                | antes del pago, esa anulacion tambien vuelve al stock porque
                | forma parte de la misma reserva que se estaba pagando.
                */
                $restorable = ['cancelled', 'expired'];

                $perType = DB::table('tickets_tickets')
                    ->where('order_id', $locked->id)
                    ->whereIn('status', $restorable)
                    ->select('ticket_type_id', DB::raw('COUNT(*) as qty'))
                    ->groupBy('ticket_type_id')
                    ->get();

                DB::table('tickets_tickets')
                    ->where('order_id', $locked->id)
                    ->whereIn('status', $restorable)
                    ->update(['status' => 'valid', 'updated_at' => now()]);

                foreach ($perType as $row) {
                    DB::table('tickets_event_ticket_types')
                        ->where('id', $row->ticket_type_id)
                        ->update([
                            'sold_count' => DB::raw('sold_count + '.(int) $row->qty),
                            'updated_at' => now(),
                        ]);
                }
            }

            DB::table('tickets_orders')->where('id', $locked->id)->update([
                'status' => 'paid',
                'payment_method' => $paymentMethod,
                'payment_reference' => $payment['preference_id'] ?? $payment['reference'] ?? null,
                'payment_external_id' => $paymentId,
                'payment_amount' => $payment['transaction_details']['net_received_amount']
                    ?? $payment['transaction_amount'] ?? null,
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

            $this->backfillQrPayloads($locked->id);

            $paidNow = true;
        });

        if ($skipReason !== null) {
            Log::critical('[OrderPayment] pago no acreditado', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'reason' => $skipReason,
            ]);

            $result['reason'] = $skipReason;

            return $result;
        }

        if (! $paidNow) {
            // Otro hilo lo acredito mientras esperabamos el lock.
            $result['reason'] = 'already_paid_same_payment';

            return $result;
        }

        if ($wasCancelled) {
            Log::critical('[OrderPayment] pago recibido sobre orden cerrada, revisar reembolso', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
            ]);
        }

        Log::info('[OrderPayment] orden pagada', [
            'order_id' => $order->id,
            'payment_id' => $paymentId,
        ]);

        Realtime::orderStatus($order->id, 'paid', (string) $order->total);
        Realtime::orderStatusForBuyer((string) $order->public_token, (int) $order->id, 'paid');
        Realtime::orderCreated((int) $order->id, (int) $order->event_id, (string) $order->buyer_name, (string) $order->total);

        $rows = DB::table('tickets_event_ticket_types')
            ->whereIn('id', DB::table('tickets_tickets')
                ->where('order_id', $order->id)
                ->distinct()
                ->pluck('ticket_type_id'))
            ->get(['id', 'event_id', 'stock', 'sold_count', 'enable']);

        if ($rows->isNotEmpty()) {
            Realtime::stockForTypes(
                (int) $rows->first()->event_id,
                $rows->map(fn ($r) => (array) $r)->all()
            );
        }

        $result['changed'] = true;
        $result['was_cancelled'] = $wasCancelled;

        return $result;
    }

    /**
     * Los QR se generan al crear la orden. Esto solo rellena los que quedaran
     * vacios (ordenes creadas antes de existir el qr_secret, por ejemplo).
     */
    private function backfillQrPayloads(int $orderId): void
    {
        $secret = QrPayload::secret();

        $empty = DB::table('tickets_tickets')
            ->where('order_id', $orderId)
            ->where(fn ($q) => $q->whereNull('qr_payload')->orWhere('qr_payload', ''))
            ->get(['id', 'uuid']);

        foreach ($empty as $ticket) {
            DB::table('tickets_tickets')->where('id', $ticket->id)->update([
                'qr_payload' => QrPayload::make($ticket->uuid, $secret),
                'updated_at' => now(),
            ]);
        }
    }
}

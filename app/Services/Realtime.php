<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Dispara los eventos de tiempo real.
 *
 * Todos los metodos se llaman DESPUES de DB::commit(). El motivo: el
 * cliente, al recibir el evento, vuelve a pedir los datos; si el broadcast
 * saliera antes del commit, podria leer el estado viejo y quedar
 * desincronizado hasta el siguiente evento.
 *
 * "vuelve a pedir los datos" en los comentarios quiere decir eso: el
 * payload solo lleva ids y valores nuevos, nunca es fuente de verdad.
 *
 * Y por lo mismo ningun broadcast puede romper la operacion: si Reverb esta
 * caido, el pedido ya quedo confirmado en la base y la respuesta tiene que
 * ser igual. Sin esta proteccion, con Reverb apagado no se podia publicar un
 * evento ni validar una entrada en la puerta, porque el 500 venia del
 * servidor de websockets y no de la operacion de negocio.
 */
class Realtime
{
    /**
     * Encola el evento y se come el error si el servidor de websockets no
     * esta disponible. El polling del frontend cubre la ventana.
     */
    private static function dispatch(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning('[Realtime] no se pudo emitir el evento, la operacion sigue', [
                'evento' => class_basename($event),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Estado de una orden para el panel.
     */
    public static function orderStatus(int $orderId, string $status, string $total = '0.00'): void
    {
        static::dispatch(new \App\Events\OrderStatusChanged($orderId, $status, $total));
    }

    /**
     * Estado de una orden para el comprador que la esta pagando.
     */
    public static function orderStatusForBuyer(string $publicToken, int $orderId, string $status): void
    {
        if ($publicToken === '') {
            return;
        }

        static::dispatch(new \App\Events\OrderStatusChangedForBuyer($publicToken, $orderId, $status));
    }

    /**
     * Stock y contador de un tipo de entrada. Refresh del portal.
     */
    public static function ticketStock(
        int $eventId,
        int $ticketTypeId,
        ?int $stock,
        int $soldCount,
        bool $enabled = true,
    ): void {
        static::dispatch(new \App\Events\TicketTypeStockChanged($eventId, $ticketTypeId, $stock, $soldCount, $enabled));
    }

    /**
     * Refresh de stock para todos los tipos de un evento. Se usa al crear o
     * cancelar una orden, que toca varios tipos a la vez.
     *
     * @param  array<int,array<string,mixed>>  $types  filas de
     *         tickets_event_ticket_types con al menos id, stock, sold_count, enable
     */
    public static function stockForTypes(int $eventId, array $types): void
    {
        foreach ($types as $type) {
            static::ticketStock(
                $eventId,
                (int) $type['id'],
                $type['stock'] === null ? null : (int) $type['stock'],
                (int) ($type['sold_count'] ?? 0),
                (bool) ($type['enable'] ?? true),
            );
        }
    }

    public static function eventStatus(int $eventId, string $status, ?string $coverImage = null): void
    {
        static::dispatch(new \App\Events\EventStatusChanged($eventId, $status, $coverImage));
    }

    public static function scanned(
        int $eventId,
        ?int $ticketId,
        string $result,
        ?string $eventName = null,
        ?string $ticketTypeName = null,
    ): void {
        static::dispatch(new \App\Events\TicketScanned($eventId, $ticketId, $result, $eventName, $ticketTypeName));
    }

    public static function orderCreated(int $orderId, int $eventId, string $buyerName, string $total): void
    {
        static::dispatch(new \App\Events\OrderCreated($orderId, $eventId, $buyerName, $total));
    }
}
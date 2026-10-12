<?php

namespace App\Services;

use App\Support\QrPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creacion de una orden con sus boletos.
 *
 * Es la parte que el portal de compra y el POS de la caja tienen que hacer
 * igual: bloquear el stock, insertar la orden y emitir un boleto por unidad.
 * Antes vivia inline en el controlador del portal; extraerla evita que el POS
 * reimplemente (o copie mal) el control de stock con lock.
 *
 * La validacion "rapida" y con mensajes claros sigue en cada controlador; la
 * que manda es la de adentro de la transaccion, que corre con lockForUpdate.
 */
class TicketOrderService
{
    /**
     * Vendedor de las ventas que entran por el sistema (portal) y no por una
     * caja: no hay operador, asi que se identifican como 'Online'.
     */
    public const ONLINE_SELLER = 'Online';

    /**
     * @param  array{id?:int,name:string,email?:?string,dni?:?string,phone?:?string}  $buyer
     * @param  array<int,array{ticket_type_id:int,qty:int,unit_price:float}>  $items
     * @param  array{payment_mode?:string,installment_count?:int,ip_address?:?string,user_agent?:?string,seller_user_id?:?int,seller_name?:?string}  $extra
     * @return array{id:int,public_token:string,total:float,ticket_count:int}
     */
    public function createOrder(object $event, array $buyer, array $items, array $extra = []): array
    {
        $total = round(array_sum(array_map(
            fn ($i) => ((float) $i['unit_price']) * ((int) $i['qty']),
            $items
        )), 2);

        $publicToken = (string) Str::uuid();
        $ticketCount = array_sum(array_column($items, 'qty'));

        /*
        | El secreto se resuelve ANTES de abrir la transaccion: si todavia no
        | existe, QrPayload::secret() lo crea, y hacerlo adentro dejaria el
        | primer QR de una instalacion nueva firmado con cadena vacia.
        */
        $qrSecret = QrPayload::secret();

        $orderId = DB::transaction(function () use (
            $event, $buyer, $items, $total, $extra, $publicToken, $qrSecret
        ) {
            /*
            | El control de stock se repite DENTRO de la transaccion, con
            | lockForUpdate. Sin el lock, dos ventas que llegan con el ultimo
            | boleto al mismo tiempo leen el mismo sold_count, las dos pasan y
            | se vende dos veces.
            */
            foreach ($items as $item) {
                $locked = DB::table('tickets_event_ticket_types')
                    ->where('id', $item['ticket_type_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    throw ValidationException::withMessages([
                        'items' => 'Uno de los tipos de entrada ya no esta disponible',
                    ]);
                }

                if ($locked->stock !== null && ($locked->sold_count + $item['qty']) > $locked->stock) {
                    throw ValidationException::withMessages([
                        'items' => "Stock insuficiente para {$locked->name}",
                    ]);
                }

                if ($item['qty'] > $locked->max_per_order) {
                    throw ValidationException::withMessages([
                        'items' => "Maximo por compra para {$locked->name}: {$locked->max_per_order}",
                    ]);
                }
            }

            $id = DB::table('tickets_orders')->insertGetId([
                'public_token' => $publicToken,
                'event_id' => $event->id,
                // Snapshot del evento: el nombre o la fecha pueden cambiar
                // despues de vendida la entrada.
                'event_name' => $event->name ?? null,
                'event_starts_at' => $event->starts_at ?? null,
                'event_location' => $event->location ?? null,
                'buyer_user_id' => $buyer['id'] ?? null,
                'seller_user_id' => $extra['seller_user_id'] ?? null,
                // Snapshot: el nombre queda aunque despues se borre el usuario.
                'seller_name' => $extra['seller_name'] ?? self::ONLINE_SELLER,
                'buyer_name' => $buyer['name'],
                'buyer_email' => (string) ($buyer['email'] ?? ''),
                'buyer_dni' => $buyer['dni'] ?? null,
                'buyer_phone' => $buyer['phone'] ?? null,
                'total' => $total,
                'subtotal' => $total,
                'payment_mode' => $extra['payment_mode'] ?? 'single',
                'installment_count' => $extra['installment_count'] ?? 1,
                'status' => 'pending',
                'ip_address' => $extra['ip_address'] ?? null,
                'user_agent' => $extra['user_agent'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($items as $it) {
                for ($i = 0; $i < $it['qty']; $i++) {
                    $uuid = (string) Str::uuid();

                    DB::table('tickets_tickets')->insert([
                        'order_id' => $id,
                        'event_id' => $event->id,
                        'ticket_type_id' => $it['ticket_type_id'],
                        'uuid' => $uuid,
                        'qr_payload' => QrPayload::make($uuid, $qrSecret),
                        'status' => 'valid',
                        'wristband_given' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('tickets_event_ticket_types')
                    ->where('id', $it['ticket_type_id'])
                    ->increment('sold_count', $it['qty']);
            }

            return $id;
        });

        return [
            'id' => $orderId,
            'public_token' => $publicToken,
            'total' => $total,
            'ticket_count' => (int) $ticketCount,
        ];
    }
}

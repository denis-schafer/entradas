<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Realtime;
use App\Support\QrPayload;
use App\Support\QrRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketPortalOrderController extends Controller
{
    /**
     * Crea una orden pendiente, reserva los boletos y descuenta stock.
     *
     * El importe NO depende de la modalidad de pago: es el mismo pagando de una
     * o en cuotas, y MercadoPago suma su propio interes al comprador. Lo unico
     * que se registra es COMO pago, para mostrárselo despues y para limitarle a
     * MercadoPago cuantas cuotas puede ofrecer. Si no viene nada se asume pago
     * unico, que es el comportamiento previo.
     */
    public function create(Request $request): JsonResponse
    {
        $buyer = $request->attributes->get('tickets_portal_user');

        if (! $buyer) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $validated = $request->validate([
            'event_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.ticket_type_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1',
            'payment_mode' => 'nullable|in:single,installments',
            'installment_count' => 'nullable|integer|min:1|max:24',
        ]);

        $paymentMode = $validated['payment_mode'] ?? 'single';
        $requestedInstallments = (int) ($validated['installment_count'] ?? 1);
        $installmentCount = $paymentMode === 'single' ? 1 : max(2, $requestedInstallments);

        $event = DB::table('tickets_events')
            ->where('id', $validated['event_id'])
            ->where('status', 'published')
            ->first();

        if (! $event) {
            return response()->json(['message' => 'Evento no disponible'], 404);
        }

        $total = 0.0;
        $maxInstallments = 24;
        $items = [];

        foreach ($validated['items'] as $item) {
            $type = DB::table('tickets_event_ticket_types')
                ->where('event_id', $event->id)
                ->where('id', $item['ticket_type_id'])
                ->where('enable', true)
                ->first();

            if (! $type) {
                return response()->json([
                    'message' => "Tipo de entrada {$item['ticket_type_id']} no disponible",
                ], 422);
            }

            if ($type->stock !== null && ($type->sold_count + $item['qty']) > $type->stock) {
                return response()->json([
                    'message' => "Stock insuficiente para {$type->name}",
                ], 422);
            }

            if ($item['qty'] > $type->max_per_order) {
                return response()->json([
                    'message' => "Maximo por compra para {$type->name}: {$type->max_per_order}",
                ], 422);
            }

            // Si el comprador pidio cuotas, TODOS los tipos de la orden tienen
            // que admitirlas: no se financia solo una parte.
            if ($paymentMode === 'installments') {
                if ($type->payment_mode === 'single' || (int) $type->max_installments < 2) {
                    return response()->json([
                        'message' => "La entrada {$type->name} no admite pago en cuotas",
                    ], 422);
                }
                $maxInstallments = min($maxInstallments, (int) $type->max_installments);
            }

            /*
             | El precio es el mismo pague de una o en cuotas. El interes (cuando
             | hay) lo determina MercadoPago segun el banco emisor y las promos
             | vigentes: lo consultamos en vivo desde /v1/payment_methods/installments
             | y se lo mostramos antes de confirmar el pedido. Inflar el precio en
             | nuestro sistema y dejarlo pasar al checkout de MP haria que MP cobre
             | su propio interes ADEMAS del recargo: el cliente terminaria
             | pagando de mas.
             */
            $unitPrice = round((float) $type->price, 2);

            $items[] = [
                'ticket_type_id' => (int) $type->id,
                'qty' => (int) $item['qty'],
                'unit_price' => $unitPrice,
            ];

            $total += $unitPrice * $item['qty'];
        }

        if ($paymentMode === 'installments') {
            $installmentCount = min($installmentCount, $maxInstallments);

            // No se recorta en silencio: si pide mas cuotas de las permitidas
            // se rechaza, para no cobrarle de una manera distinta.
            if ($installmentCount < $requestedInstallments) {
                return response()->json([
                    'message' => "La cantidad de cuotas elegida supera el maximo permitido ({$maxInstallments})",
                ], 422);
            }
        }

        $total = round($total, 2);
        $publicToken = (string) Str::uuid();

        // El secreto se resuelve ANTES de abrir la transaccion y no dentro: si
        // todavia no existe, QrPayload::secret() lo crea, y hacerlo aca evita
        // que el primer QR de una instalacion nueva quede firmado con cadena
        // vacia (que despues el escaner rechaza por firma invalida).
        $qrSecret = QrPayload::secret();
        $ticketCount = array_sum(array_column($items, 'qty'));

        $orderId = DB::transaction(function () use (
            $event, $buyer, $items, $total, $paymentMode, $installmentCount,
            $publicToken, $qrSecret, $ticketCount, $request
        ) {
            /*
            | El control de stock se repite DENTRO de la transaccion, con
            | lockForUpdate. La validacion de arriba es para responder rapido y
            | con un mensaje claro; esta es la que manda. Sin el lock, dos
            | compradores que llegan con el ultimo boleto al mismo tiempo leen
            | el mismo sold_count, los dos pasan el chequeo y se vende dos veces.
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
                'buyer_user_id' => $buyer['id'],
                'buyer_name' => $buyer['name'],
                'buyer_email' => (string) ($buyer['email'] ?? ''),
                'buyer_dni' => $buyer['dni'] ?? null,
                'buyer_phone' => $buyer['phone'] ?? null,
                'total' => $total,
                'subtotal' => $total,
                'payment_mode' => $paymentMode,
                'installment_count' => $installmentCount,
                'status' => 'pending',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
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

        $this->broadcastOrderChange($orderId, 'pending');
        $this->broadcastStock($items);

        // El panel ya puede mostrar la venta en vivo, sin esperar al webhook.
        Realtime::orderCreated($orderId, (int) $event->id, (string) $buyer['name'], number_format($total, 2, '.', ''));

        return response()->json([
            'order_id' => $orderId,
            'public_token' => $publicToken,
            'total' => $total,
            'subtotal' => $total,
            'payment_mode' => $paymentMode,
            'installment_count' => $installmentCount,
            'ticket_count' => $ticketCount,
        ], 201);
    }

    public function myTickets(Request $request): JsonResponse
    {
        $buyer = $request->attributes->get('tickets_portal_user');

        if (! $buyer) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $tickets = DB::table('tickets_tickets as t')
            ->leftJoin('tickets_event_ticket_types as tt', 't.ticket_type_id', '=', 'tt.id')
            ->leftJoin('tickets_events as e', 't.event_id', '=', 'e.id')
            ->leftJoin('tickets_orders as o', 't.order_id', '=', 'o.id')
            ->where('o.buyer_user_id', $buyer['id'])
            ->where('o.status', '!=', 'cancelled')
            ->where('t.status', '!=', 'cancelled')
            ->select([
                't.id', 't.uuid', 't.qr_payload', 't.status', 't.used_at',
                't.wristband_given', 't.wristband_color',
                'tt.name as type_name', 'tt.wristband_color as type_wristband_color',
                'tt.wristband_label', 'tt.image_path as type_image_path',
                'e.name as event_name', 'e.starts_at', 'e.location', 'e.cover_image as event_cover',
                'o.id as order_id', 'o.public_token as public_token', 'o.status as order_status', 'o.paid_at',
            ])
            ->orderByDesc('t.id')
            ->get();

        return response()->json($tickets);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $buyer = $request->attributes->get('tickets_portal_user');

        if (! $buyer) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $orders = DB::table('tickets_orders as o')
            ->leftJoin('tickets_events as e', 'o.event_id', '=', 'e.id')
            ->where('o.buyer_user_id', $buyer['id'])
            ->where('o.status', '!=', 'cancelled')
            ->select([
                'o.id', 'o.public_token', 'o.event_id', 'o.total', 'o.status',
                'o.payment_mode', 'o.installment_count',
                'o.mp_payment_id', 'o.created_at', 'o.paid_at',
                'e.name as event_name', 'e.starts_at', 'e.cover_image as event_cover',
            ])
            ->orderByDesc('o.id')
            ->get();

        $counts = DB::table('tickets_tickets')
            ->select('order_id', DB::raw('COUNT(*) as total'))
            ->whereIn('order_id', $orders->pluck('id'))
            ->groupBy('order_id')
            ->pluck('total', 'order_id');

        foreach ($orders as $o) {
            $o->ticket_count = (int) ($counts[$o->id] ?? 0);
        }

        return response()->json($orders);
    }

    /**
     * Estado de una orden. Es la fuente de verdad; el websocket solo acelera.
     */
    public function paymentStatus(Request $request, int $orderId): JsonResponse
    {
        $buyer = $request->attributes->get('tickets_portal_user');

        if (! $buyer) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $order = DB::table('tickets_orders')
            ->where('id', $orderId)
            ->where('buyer_user_id', $buyer['id'])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        return response()->json([
            'id' => $order->id,
            'public_token' => $order->public_token,
            'status' => $order->status,
            'mp_payment_id' => $order->mp_payment_id,
            'paid_at' => $order->paid_at,
        ]);
    }

    /**
 * QR del propio boleto, para que el comprador lo vea en el celu sin venir a la
 * caja. Solo responde si la entrada es de una orden suya.
 */
public function myTicketQr(Request $request, int $ticketId)
{
    $buyer = $request->attributes->get('tickets_portal_user');

    if (! $buyer) {
        return response()->json(['message' => 'No autenticado'], 401);
    }

    $request->validate(['size' => 'nullable|integer|min:120|max:1024']);

    $ticket = DB::table('tickets_tickets as t')
        ->join('tickets_orders as o', 't.order_id', '=', 'o.id')
        ->where('t.id', $ticketId)
        ->where('o.buyer_user_id', $buyer['id'])
        ->where('o.status', 'paid')
        ->first(['t.uuid', 't.qr_payload', 't.status']);

    if (! $ticket || empty($ticket->qr_payload)) {
        abort(404);
    }

    // Anulada o ya usada no se muestra: no sirve de nada y confunde.
    if ($ticket->status === 'cancelled') {
        abort(404);
    }

    return response(QrRenderer::svg($ticket->qr_payload, (int) $request->query('size', 400)), 200, [
        'Content-Type' => 'image/svg+xml',
        'Cache-Control' => 'private, max-age=300',
    ]);
}

/**
 * Cancela una orden del comprador y libera el stock reservado.
     * Solo aplica a ordenes no pagadas: nunca se borra informacion financiera
     * ni entradas ya escaneadas.
     */
    public function cancel(Request $request, int $orderId): JsonResponse
    {
        $buyer = $request->attributes->get('tickets_portal_user');

        if (! $buyer) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $order = DB::table('tickets_orders')
            ->where('id', $orderId)
            ->where('buyer_user_id', $buyer['id'])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        if ($order->status === 'paid') {
            return response()->json([
                'message' => 'No se puede cancelar una orden ya pagada. Contactanos para el reembolso.',
            ], 422);
        }

        if (in_array($order->status, ['cancelled', 'expired'], true)) {
            return response()->json(['message' => 'La orden ya esta cancelada'], 422);
        }

        $usedCount = DB::table('tickets_tickets')
            ->where('order_id', $order->id)
            ->where('status', 'used')
            ->count();

        if ($usedCount > 0) {
            return response()->json([
                'message' => 'No se puede cancelar: hay entradas ya utilizadas en el evento.',
            ], 422);
        }

        DB::transaction(function () use ($order) {
            /*
            | El lock sobre la orden serializa las cancelaciones. Si el comprador
            | toca "cancelar" dos veces (o el panel y el portal a la vez), sin el
            | lock las dos transacciones ven los mismos boletos validos y cada
            | una devuelve el stock: sold_count baja de ahi en lugar de cero.
            */
            DB::table('tickets_orders')->where('id', $order->id)->lockForUpdate()->first();

            // El conteo se hace adentro, con la orden ya bloqueada: el de arriba
            // era solo un chequeo temprano para responder sin esperar el lock.
            $perType = DB::table('tickets_tickets')
                ->where('order_id', $order->id)
                ->where('status', 'valid')
                ->select('ticket_type_id', DB::raw('COUNT(*) as qty'))
                ->groupBy('ticket_type_id')
                ->get();

            foreach ($perType as $row) {
                DB::table('tickets_tickets')
                    ->where('order_id', $order->id)
                    ->where('ticket_type_id', $row->ticket_type_id)
                    ->where('status', 'valid')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);

                // sold_count se incremento al crear la orden: hay que devolverlo.
                DB::table('tickets_event_ticket_types')
                    ->where('id', $row->ticket_type_id)
                    ->update([
                        'sold_count' => DB::raw('GREATEST(sold_count - '.(int) $row->qty.', 0)'),
                        'updated_at' => now(),
                    ]);
            }

            DB::table('tickets_orders')
                ->where('id', $order->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'mp_preference_id' => null,
                    'updated_at' => now(),
                ]);
        });

        $this->broadcastOrderChange($order->id, 'cancelled');
        // Los tipos se releen porque los que se liberaron son los que quedaron
        // en 'valid' despues de la transaccion, no los que habia antes.
        $this->broadcastStock(
            DB::table('tickets_tickets')
                ->where('order_id', $order->id)
                ->where('status', 'cancelled')
                ->select('ticket_type_id', DB::raw('COUNT(*) as qty'))
                ->groupBy('ticket_type_id')
                ->get()
                ->map(fn ($r) => ['id' => $r->ticket_type_id, 'qty' => (int) $r->qty])
                ->all()
        );

        return response()->json([
            'message' => 'Orden cancelada',
            'order_id' => $order->id,
        ]);
    }

    /**
     * Tras crear o cancelar una orden hay que reemitir el stock de los tipos
     * afectados, leido fresco de la base.
     *
     * Acepta las dos formas en que se llama: 'ticket_type_id' desde el carrito
     * de create() y 'id' desde los agrupados de cancel().
     *
     * @param  array<int,array<string,mixed>>  $items
     */
    private function broadcastStock(array $items): void
    {
        $typeIds = array_values(array_unique(array_filter(array_map(
            fn ($i) => (int) ($i['id'] ?? $i['ticket_type_id'] ?? 0),
            $items
        ))));

        if (! $typeIds) {
            return;
        }

        $rows = DB::table('tickets_event_ticket_types')
            ->whereIn('id', $typeIds)
            ->get(['id', 'event_id', 'stock', 'sold_count', 'enable']);

        Realtime::stockForTypes(
            (int) ($rows->first()->event_id ?? 0),
            $rows->map(fn ($r) => (array) $r)->all()
        );
    }

    private function broadcastOrderChange(int $orderId, string $status): void
    {
        $order = DB::table('tickets_orders')->where('id', $orderId)->first();

        if (! $order) {
            return;
        }

        Realtime::orderStatus($orderId, $status, (string) $order->total);
        Realtime::orderStatusForBuyer((string) $order->public_token, $orderId, $status);
    }
}
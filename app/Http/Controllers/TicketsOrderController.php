<?php

namespace App\Http\Controllers;

use App\Services\OrderPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TicketsOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('tickets_orders as o')
            ->leftJoin('tickets_events as e', 'o.event_id', '=', 'e.id')
            ->select([
                'o.id', 'o.public_token', 'o.buyer_name', 'o.buyer_email', 'o.buyer_dni',
                'o.total', 'o.status', 'o.payment_mode', 'o.installment_count',
                'o.mp_payment_id', 'o.paid_at', 'o.created_at', 'o.event_id',
                'e.name as event_name',
            ])
            ->orderByDesc('o.id');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('o.buyer_name', 'like', "%{$search}%")
                    ->orWhere('o.buyer_email', 'like', "%{$search}%")
                    ->orWhere('o.buyer_dni', 'like', "%{$search}%")
                    ->orWhere('o.mp_payment_id', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('o.status', $status);
        }

        if ($eventId = $request->query('event_id')) {
            $query->where('o.event_id', (int) $eventId);
        }

        $orders = $query->paginate(20);

        $counts = DB::table('tickets_tickets')
            ->select('order_id', DB::raw('COUNT(*) as total'))
            ->whereIn('order_id', collect($orders->items())->pluck('id'))
            ->groupBy('order_id')
            ->pluck('total', 'order_id');

        foreach ($orders->items() as $order) {
            $order->ticket_count = (int) ($counts[$order->id] ?? 0);
        }

        return response()->json($orders);
    }

    public function show(int $id): JsonResponse
    {
        $order = DB::table('tickets_orders as o')
            ->leftJoin('tickets_events as e', 'o.event_id', '=', 'e.id')
            ->where('o.id', $id)
            ->select(['o.*', 'e.name as event_name', 'e.starts_at as event_starts_at', 'e.location as event_location'])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        $order->tickets = DB::table('tickets_tickets as t')
            ->leftJoin('tickets_event_ticket_types as tt', 't.ticket_type_id', '=', 'tt.id')
            ->where('t.order_id', $id)
            ->select([
                't.id', 't.uuid', 't.status', 't.used_at', 't.wristband_given', 't.wristband_color',
                'tt.name as type_name',
            ])
            ->orderBy('t.id')
            ->get();

        return response()->json($order);
    }

    /**
     * Reconcilia una orden con MercadoPago consultando los pagos que MP tiene
     * registrados. Pensado para cuando los webhooks no llegaron (entornos de
     * desarrollo detras de localhost, deploy recien hecho, MP con problemas
     * de entrega, etc.).
     *
     * El admin clickea "Reconciliar" -> el server busca pagos por la
     * external_reference de la orden (que ya se guardo al crear la preference)
     * y, si encuentra uno aprobado, marca la orden como pagada usando la misma
     * logica que el webhook. Es idempotente: si la orden ya esta pagada, no
     * hace nada y avisa.
     *
     * Requiere el access_token del evento (per-evento) porque MP devuelve los
     * pagos de la cuenta del organizador que cobro, no de la del desarrollador.
     */
    public function reconcile(Request $request, int $id): JsonResponse
    {
        $order = DB::table('tickets_orders')->where('id', $id)->first();

        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        if ($order->status === 'paid') {
            return response()->json([
                'message' => 'La orden ya esta marcada como pagada.',
                'order_id' => $order->id,
                'mp_payment_id' => $order->mp_payment_id,
                'paid_at' => $order->paid_at,
            ]);
        }

        $event = DB::table('tickets_events')->where('id', $order->event_id)->first();
        $accessToken = $event->mp_access_token ?? null;

        if (empty($accessToken)) {
            return response()->json([
                'message' => 'El evento no tiene MercadoPago configurado. No se puede consultar el pago.',
            ], 422);
        }

        $externalRef = 'ENTRADAS-TICKET-'.$order->id;

        try {
            /*
             | /v1/payments/search por external_reference devuelve los pagos
             | que MP tiene registrados para esta orden. Como la preference
             | puede recibir varios pagos (raro pero pasa), preferimos el
             | aprobado mas reciente.
             */
            $resp = Http::withToken($accessToken)->timeout(15)->get(
                'https://api.mercadopago.com/v1/payments/search',
                [
                    'external_reference' => $externalRef,
                    'sort' => 'date_approved',
                    'criteria' => 'desc',
                    'limit' => 20,
                ]
            );

            if ($resp->failed()) {
                Log::warning('[Reconcile] MP search failed', [
                    'order_id' => $order->id,
                    'status' => $resp->status(),
                    'body' => substr($resp->body(), 0, 500),
                ]);

                return response()->json([
                    'message' => 'MercadoPago no respondio la consulta (status '.$resp->status().').',
                ], 502);
            }

            $results = $resp->json('results') ?? [];

            if ($results === []) {
                return response()->json([
                    'message' => 'MercadoPago no tiene pagos registrados para esta orden. '
                        .'Si el cliente dice que pago, esperar unos minutos y reintentar.',
                    'order_id' => $order->id,
                    'external_reference' => $externalRef,
                ], 404);
            }

            /*
             | Preferimos un pago 'approved'. Si no hay ninguno, devolvemos un
             | resumen de lo que MP tiene para que el operador vea que paso.
             */
            $approved = null;

            foreach ($results as $payment) {
                if (($payment['status'] ?? '') === 'approved') {
                    $approved = $payment;

                    break;
                }
            }

            if (! $approved) {
                $statuses = array_count_values(array_map(
                    fn ($p) => (string) ($p['status'] ?? 'unknown'),
                    $results
                ));

                return response()->json([
                    'message' => 'Hay pagos en MP pero ninguno aprobado. La orden queda pendiente.',
                    'order_id' => $order->id,
                    'payments_found' => count($results),
                    'statuses' => $statuses,
                ], 422);
            }

            $result = app(OrderPaymentService::class)->markPaid(
                $order,
                (string) $approved['id'],
                $approved
            );

            if (! $result['changed']) {
                return response()->json([
                    'message' => 'La orden ya estaba pagada con otro pago (idempotente).',
                    'order_id' => $order->id,
                    'reason' => $result['reason'],
                ]);
            }

            return response()->json([
                'message' => $result['was_cancelled']
                    ? 'Orden pagada. Estaba cancelada y se reactivo la reserva.'
                    : 'Orden pagada y marcada como tal.',
                'order_id' => $order->id,
                'mp_payment_id' => $approved['id'],
                'mp_transaction_amount' => $approved['transaction_amount'] ?? null,
                'was_cancelled' => $result['was_cancelled'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Reconcile] exception', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error: '.$e->getMessage(),
            ], 500);
        }
    }
}
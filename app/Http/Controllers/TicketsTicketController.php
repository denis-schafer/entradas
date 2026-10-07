<?php

namespace App\Http\Controllers;

use App\Services\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketsTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('tickets_tickets as t')
            ->leftJoin('tickets_event_ticket_types as tt', 't.ticket_type_id', '=', 'tt.id')
            ->leftJoin('tickets_events as e', 't.event_id', '=', 'e.id')
            ->leftJoin('tickets_orders as o', 't.order_id', '=', 'o.id')
            ->select([
                't.id', 't.uuid', 't.status', 't.used_at', 't.wristband_given', 't.wristband_color',
                't.created_at', 't.event_id', 't.ticket_type_id', 't.order_id',
                'tt.name as type_name',
                'e.name as event_name',
                'o.buyer_name', 'o.buyer_email', 'o.status as order_status',
            ])
            ->orderByDesc('t.id');

        if ($eventId = $request->query('event_id')) {
            $query->where('t.event_id', (int) $eventId);
        }

        if ($status = $request->query('status')) {
            $query->where('t.status', $status);
        }

        /*
        | Filtro por estado de la orden (pagadas, pendientes, etc). Es un
        | concepto independiente del estado del boleto: una entrada pagada puede
        | estar valid o used, una entrada pending nunca deberia existir pero el
        | filtro igual las muestra si quedan por algun motivo.
        */
        if ($orderStatus = $request->query('order_status')) {
            $query->where('o.status', $orderStatus);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('t.uuid', 'like', "%{$search}%")
                    ->orWhere('o.buyer_name', 'like', "%{$search}%")
                    ->orWhere('o.buyer_email', 'like', "%{$search}%")
                    ->orWhere('o.buyer_dni', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate(30));
    }

    public function show(int $id): JsonResponse
    {
        $ticket = DB::table('tickets_tickets as t')
            ->leftJoin('tickets_event_ticket_types as tt', 't.ticket_type_id', '=', 'tt.id')
            ->leftJoin('tickets_events as e', 't.event_id', '=', 'e.id')
            ->leftJoin('tickets_orders as o', 't.order_id', '=', 'o.id')
            ->where('t.id', $id)
            ->select([
                't.*',
                'tt.name as type_name', 'tt.wristband_color', 'tt.wristband_label',
                'e.name as event_name',
                'o.buyer_name', 'o.buyer_email', 'o.buyer_dni', 'o.buyer_phone',
            ])
            ->first();

        if (! $ticket) {
            return response()->json(['message' => 'Entrada no encontrada'], 404);
        }

        $ticket->scans = DB::table('tickets_scans')
            ->where('ticket_id', $id)
            ->orderByDesc('scanned_at')
            ->get();

        return response()->json($ticket);
    }

    /**
     * Entrega manual de una pulsera. No cambia el estado del boleto: solo
     * marca que ya se entrego la pulsera, para que el personal de puerta no
     * entregue dos veces.
     */
    public function giveWristband(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('tickets_tickets')->where('id', $id)->first();

        if (! $ticket) {
            return response()->json(['message' => 'Entrada no encontrada'], 404);
        }

        $data = ['wristband_given' => ! $ticket->wristband_given, 'updated_at' => now()];

        if ($request->boolean('give', true)) {
            $data['wristband_given'] = true;
            $data['wristband_color'] = $request->input('wristband_color')
                ?? DB::table('tickets_event_ticket_types')->where('id', $ticket->ticket_type_id)->value('wristband_color');
        }

        DB::table('tickets_tickets')->where('id', $id)->update($data);

        return response()->json([
            'message' => $data['wristband_given'] ? 'Pulsera entregada' : 'Entrega de pulsera revertida',
            'wristband_given' => $data['wristband_given'],
            'wristband_color' => $data['wristband_color'] ?? $ticket->wristband_color,
        ]);
    }

    /**
     * Anula un boleto suelto y libera su stock. Solo aplica a boletos de
     * ordenes no pagadas: un boleto pagado nunca se borra, se cancela.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('tickets_tickets')
            ->leftJoin('tickets_orders as o', 'tickets_tickets.order_id', '=', 'o.id')
            ->where('tickets_tickets.id', $id)
            ->select(['tickets_tickets.*', 'o.status as order_status'])
            ->first();

        if (! $ticket) {
            return response()->json(['message' => 'Entrada no encontrada'], 404);
        }

        if ($ticket->status === 'used') {
            return response()->json([
                'message' => 'No se puede anular una entrada ya utilizada',
            ], 422);
        }

        if ($ticket->order_status === 'paid') {
            return response()->json([
                'message' => 'La orden esta pagada: no se anula desde aca. Reembolsa la orden primero.',
            ], 422);
        }

        if ($ticket->status === 'cancelled') {
            return response()->json(['message' => 'La entrada ya esta anulada'], 422);
        }

        DB::transaction(function () use ($ticket) {
            // Igual que en el escaneo: la condicion va en el where, asi una
            // anulacion repetida no descuenta stock dos veces.
            $cancelled = DB::table('tickets_tickets')
                ->where('id', $ticket->id)
                ->where('status', 'valid')
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

            if (! $cancelled) {
                throw ValidationException::withMessages([
                    'ticket' => 'La entrada ya no esta vigente',
                ]);
            }

            DB::table('tickets_event_ticket_types')
                ->where('id', $ticket->ticket_type_id)
                ->update([
                    'sold_count' => DB::raw('GREATEST(sold_count - 1, 0)'),
                    'updated_at' => now(),
                ]);
        });

        $fresh = DB::table('tickets_event_ticket_types')->where('id', $ticket->ticket_type_id)->first();

        Realtime::ticketStock(
            (int) $ticket->event_id,
            (int) $ticket->ticket_type_id,
            $fresh->stock === null ? null : (int) $fresh->stock,
            (int) $fresh->sold_count,
            (bool) $fresh->enable
        );

        return response()->json(['message' => 'Entrada anulada']);
    }
}
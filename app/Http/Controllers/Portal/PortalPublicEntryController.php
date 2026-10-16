<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Support\QrRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Entrada publica de una venta, para que el vendedor le mande el link al
 * comprador por WhatsApp y este vea sus entradas sin tener que abrir sesion.
 *
 * La credencial del link es el public_token de la orden: un UUID aleatorio
 * que solo existe para cada venta. Quien lo tenga ve unicamente la compra
 * correspondiente (evento y boletos con su QR): no crea sesion, no toca el
 * perfil, no ve otras compras. Es como un boarding pass, y por eso no tiene
 * expiracion: lo que expone es la entrada que el comprador ya posee.
 *
 * El QR no puede servir el payload firmado como si fuera quien lo emite: solo
 * dibuja el payload ya firmado al momento de la venta, y solo si la orden
 * esta pagada y la entrada no esta anulada.
 */
class PortalPublicEntryController extends Controller
{
    /**
     * Datos de la venta para la vista publica del comprador.
     */
    public function show(string $publicToken): JsonResponse
    {
        $order = DB::table('tickets_orders as o')
            ->leftJoin('tickets_events as e', 'e.id', '=', 'o.event_id')
            ->where('o.public_token', $publicToken)
            ->where('o.status', '!=', 'cancelled')
            ->select([
                'o.id', 'o.status', 'o.paid_at',
                'e.id as event_id', 'e.name as event_name', 'e.starts_at',
                'e.location', 'e.cover_image as event_cover',
            ])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'No se encontro la entrada'], 404);
        }

        $tickets = DB::table('tickets_tickets as t')
            ->leftJoin('tickets_event_ticket_types as tt', 't.ticket_type_id', '=', 'tt.id')
            ->where('t.order_id', $order->id)
            ->where('t.status', '!=', 'cancelled')
            ->orderBy('t.id')
            ->select([
                't.id', 't.number', 't.uuid', 't.status', 't.used_at',
                't.wristband_given', 't.wristband_color',
                'tt.name as type_name',
                'tt.wristband_color as type_wristband_color', 'tt.wristband_label',
            ])
            ->get();

        return response()->json([
            'order' => $order,
            'tickets' => $tickets,
        ]);
    }

    /**
     * QR de una entrada de esa venta, para la vista publica. Sirve porque el
     * payload ya quedo firmado al venderse; el navegador solo mira el dibujo.
     */
    public function ticketQr(Request $request, string $publicToken, int $ticketId)
    {
        $request->validate(['size' => 'nullable|integer|min:120|max:1024']);

        $ticket = DB::table('tickets_tickets as t')
            ->join('tickets_orders as o', 't.order_id', '=', 'o.id')
            ->where('o.public_token', $publicToken)
            ->where('o.status', 'paid')
            ->where('t.id', $ticketId)
            ->where('t.status', '!=', 'cancelled')
            ->first(['t.id', 't.number', 't.qr_payload']);

        if (! $ticket || empty($ticket->qr_payload)) {
            abort(404);
        }

        // El numero de sorteo viaja dentro del SVG: asi aparece en la vista
        // publica del link y en el archivo si el comprador lo descarga.
        $caption = $ticket->number === null ? null : 'N° '.(int) $ticket->number;

        return response(QrRenderer::svg($ticket->qr_payload, (int) $request->query('size', 400), $caption), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
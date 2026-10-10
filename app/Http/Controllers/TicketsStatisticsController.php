<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketsStatisticsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $eventId = $request->query('event_id') ? (int) $request->query('event_id') : null;

        $events = DB::table('tickets_events');
        $orders = DB::table('tickets_orders as o');

        if ($eventId) {
            $events->where('id', $eventId);
            $orders->where('o.event_id', $eventId);
        }

        $tickets = DB::table('tickets_tickets as t')
            ->join('tickets_orders as o', 't.order_id', '=', 'o.id');

        if ($eventId) {
            $tickets->where('o.event_id', $eventId);
        }

        $scans = DB::table('tickets_scans as s');

        if ($eventId) {
            $scans->where('s.event_id', $eventId);
        }

        $byEvent = DB::table('tickets_events as e')
            ->leftJoin('tickets_orders as o', function ($join) {
                $join->on('o.event_id', '=', 'e.id')->where('o.status', 'paid');
            })
            ->groupBy('e.id', 'e.name', 'e.starts_at')
            ->select([
                'e.id', 'e.name', 'e.starts_at',
                DB::raw('COUNT(o.id) as orders_count'),
                DB::raw('COALESCE(SUM(o.total), 0) as revenue'),
            ])
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        // Venta por tipo de entrada: es el dato que decide si conviene abrir
        // o cerrar un tipo antes de que se agote.
        $byType = DB::table('tickets_event_ticket_types as tt')
            ->leftJoin('tickets_events as e', 'tt.event_id', '=', 'e.id')
            ->groupBy('tt.id', 'tt.name', 'tt.event_id', 'tt.price', 'tt.stock', 'tt.sold_count', 'e.name')
            ->select([
                'tt.id', 'tt.name', 'tt.event_id', 'tt.price', 'tt.stock', 'tt.sold_count',
                'e.name as event_name',
                DB::raw('tt.sold_count * tt.price as revenue'),
            ])
            ->orderByDesc('tt.sold_count')
            ->limit(15)
            ->get();

        return response()->json([
            'events' => [
                'total' => (clone $events)->count(),
                'published' => (clone $events)->where('status', 'published')->count(),
            ],
            'orders' => [
                'total' => (clone $orders)->count(),
                'paid' => (clone $orders)->where('o.status', 'paid')->count(),
                'pending' => (clone $orders)->where('o.status', 'pending')->count(),
            ],
            'tickets' => [
                'total' => (clone $tickets)->count(),
                'valid' => (clone $tickets)->where('t.status', 'valid')->count(),
                'used' => (clone $tickets)->where('t.status', 'used')->count(),
            ],
            'revenue' => (float) (clone $orders)->where('o.status', 'paid')->sum('o.total'),
            'scans' => (clone $scans)->count(),
            'by_event' => $byEvent,
            'by_type' => $byType,
        ]);
    }

    /**
     * Exportacion CSV de ordenes. Se genera en streaming porque el listado
     * completo no entra comodo en memoria en eventos grandes.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = DB::table('tickets_orders as o')
            ->leftJoin('tickets_events as e', 'o.event_id', '=', 'e.id')
            ->select([
                'o.id', 'o.public_token', 'o.event_id', 'e.name as event_name',
                'o.buyer_name', 'o.buyer_email', 'o.buyer_dni', 'o.buyer_phone',
                'o.subtotal', 'o.total', 'o.payment_mode', 'o.installment_count',
                'o.status', 'o.payment_external_id', 'o.payment_amount',
                'o.created_at', 'o.paid_at',
            ])
            ->orderByDesc('o.id');

        if ($eventId = $request->query('event_id')) {
            $query->where('o.event_id', (int) $eventId);
        }

        if ($status = $request->query('status')) {
            $query->where('o.status', $status);
        }

        $rows = $query->cursor();

        $filename = 'entradas_ordenes_'.now()->format('Ymd_His').'.csv';

        $callback = function () use ($rows) {
            $out = fopen('php://output', 'w');

            // BOM para que Excel abra los acentos bien.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'ID', 'Token', 'Evento', 'Comprador', 'Email', 'DNI', 'Telefono',
                'Subtotal', 'Total', 'Modo de pago', 'Cuotas', 'Estado',
                'MP Payment ID', 'MP Neto', 'Creada', 'Pagada',
            ]);

            foreach ($rows as $o) {
                fputcsv($out, [
                    $o->id, $o->public_token, $o->event_name, $o->buyer_name, $o->buyer_email,
                    $o->buyer_dni, $o->buyer_phone, $o->subtotal, $o->total,
                    $o->payment_mode, $o->installment_count, $o->status,
                    $o->payment_external_id, $o->payment_amount, $o->created_at, $o->paid_at,
                ]);
            }

            fclose($out);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
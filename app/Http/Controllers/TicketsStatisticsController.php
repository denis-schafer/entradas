<?php

namespace App\Http\Controllers;

use App\Support\PaymentMethodFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketsStatisticsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $eventId = $request->query('event_id') ? (int) $request->query('event_id') : null;

        /*
        | El administrativo solo ve las estadisticas de los eventos que tiene
        | asignados. Si ademas filtra por uno puntual, la interseccion deja las
        | cosas en su lugar: pedir un evento ajeno devuelve vacio, no el evento
        | entero. Para el admin $allowedIds queda en null, o sea "todos".
        */
        $allowedIds = $this->scopeEventIds($request, $eventId);

        $paymentMethod = PaymentMethodFilter::normalize($request->query('payment_method'));

        $events = DB::table('tickets_events');
        $orders = DB::table('tickets_orders as o');

        if ($allowedIds !== null) {
            $events->whereIn('id', $allowedIds);
            $orders->whereIn('o.event_id', $allowedIds);
        }

        PaymentMethodFilter::constrain($orders, $paymentMethod, 'o.payment_method', 'o.payment_variant');

        $tickets = DB::table('tickets_tickets as t')
            ->join('tickets_orders as o', 't.order_id', '=', 'o.id');

        $scans = DB::table('tickets_scans as s');

        if ($allowedIds !== null) {
            $tickets->whereIn('o.event_id', $allowedIds);
            $scans->whereIn('s.event_id', $allowedIds);
        }

        PaymentMethodFilter::constrain($tickets, $paymentMethod, 'o.payment_method', 'o.payment_variant');

        $byEvent = DB::table('tickets_events as e')
            ->leftJoin('tickets_orders as o', function ($join) use ($paymentMethod) {
                $join->on('o.event_id', '=', 'e.id')->where('o.status', 'paid');
                PaymentMethodFilter::constrain($join, $paymentMethod, 'o.payment_method', 'o.payment_variant');
            })
            ->when($allowedIds !== null, fn ($q) => $q->whereIn('e.id', $allowedIds))
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
            ->when($allowedIds !== null, fn ($q) => $q->whereIn('tt.event_id', $allowedIds))
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
        $eventId = $request->query('event_id') ? (int) $request->query('event_id') : null;
        $allowedIds = $this->scopeEventIds($request, $eventId);

        $query = DB::table('tickets_orders as o')
            ->leftJoin('tickets_events as e', 'o.event_id', '=', 'e.id')
            ->select([
                'o.id', 'o.public_token', 'o.event_id',
                DB::raw('COALESCE(o.event_name, e.name) as event_name'),
                'o.buyer_name', 'o.buyer_email', 'o.buyer_dni', 'o.buyer_phone',
                'o.seller_name',
                'o.subtotal', 'o.total', 'o.payment_mode', 'o.installment_count',
                'o.payment_method', 'o.payment_variant',
                'o.status', 'o.payment_external_id', 'o.payment_amount',
                'o.created_at', 'o.paid_at',
            ])
            ->orderByDesc('o.id');

        if ($allowedIds !== null) {
            $query->whereIn('o.event_id', $allowedIds);
        }

        if ($status = $request->query('status')) {
            $query->where('o.status', $status);
        }

        PaymentMethodFilter::apply($query, $request->query('payment_method'), 'o.payment_method', 'o.payment_variant');

        $rows = $query->cursor();

        $filename = 'entradas_ordenes_'.now()->format('Ymd_His').'.csv';

        $callback = function () use ($rows) {
            $out = fopen('php://output', 'w');

            // BOM para que Excel abra los acentos bien.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'ID', 'Token', 'Evento', 'Comprador', 'Email', 'DNI', 'Telefono', 'Vendedor',
                'Subtotal', 'Total', 'Modo de pago', 'Medio de pago', 'Modalidad',
                'Cuotas', 'Estado', 'MP Payment ID', 'MP Neto', 'Creada', 'Pagada',
            ]);

            foreach ($rows as $o) {
                $method = $o->payment_method ?: 'efectivo';

                if ($method === 'multipago') {
                    $method = 'Multipago ('.(($o->payment_variant ?? '') === 'button' ? 'boton de pago' : 'QR').')';
                }

                fputcsv($out, [
                    $o->id, $o->public_token, $o->event_name, $o->buyer_name, $o->buyer_email,
                    $o->buyer_dni, $o->buyer_phone, $o->seller_name, $o->subtotal, $o->total,
                    $o->payment_mode, $method, $o->payment_variant ?: '',
                    $o->installment_count, $o->status,
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

    /**
     * IDs de evento sobre los que el usuario puede ver estadisticas.
     *
     * Devuelve null cuando no hay restriccion (el administrador ve todo), y la
     * lista ya recortada cuando el usuario esta limitado a sus eventos. Si
     * pidio un evento puntual que no le pertenece, la interseccion deja [].
     *
     * @return array<int,int>|null
     */
    private function scopeEventIds(Request $request, ?int $eventId): ?array
    {
        $user = $request->user();

        if (! $user || $user->isStaffAdmin()) {
            return $eventId ? [$eventId] : null;
        }

        $assigned = $user->assignedEventIds();

        if ($eventId) {
            return array_values(array_intersect([$eventId], $assigned));
        }

        return $assigned;
    }
}
<?php

namespace App\Http\Controllers;

use App\Services\Realtime;
use App\Support\QrPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketsScanController extends Controller
{
    public function scan(Request $request): JsonResponse
    {
        $scanner = $request->user();

        $validated = $request->validate([
            'qr_payload' => 'required|string',
            'event_id' => 'required|integer',
        ]);

        $eventId = (int) $validated['event_id'];

        /*
        | El cajero solo escanea eventos asignados. Se corta antes de tocar
        | nada: sin asignacion no hay escaneo posible, y un evento ajeno
        | tampoco. El administrador no se limita.
        */
        if ($scanner?->isCashier() && ! in_array($eventId, $scanner->assignedEventIds(), true)) {
            return response()->json([
                'result' => 'forbidden',
                'message' => 'No estas asignado a este evento',
            ], 403);
        }

        // QrPayload::secret() crea el secreto si falta. Es la garantia de que
        // "Sistema sin configurar" no aparece nunca: si hay boletos emitidos,
        // el secreto tiene que existir, y si no hay ninguno todavia, todavia
        // no hay nada legitimo que escanear.
        $secret = QrPayload::secret();

        $raw = trim($validated['qr_payload']);
        $payload = QrPayload::verify($raw, $secret);

        /*
        | Ademas del QR firmado se acepta el uuid pelado (el que se muestra
        | impreso debajo del QR en el portal): quien no puede escanear el
        | codigo puede copiar ese numero y pegarlo, y se valida igual contra
        | la tabla de boletos. Solo entra si tiene forma de uuid: cualquier
        | otra basura sigue cayendo en "QR no valido".
        */
        if (! $payload && ($uuid = self::looseUuid($raw))) {
            $payload = ['uuid' => $uuid];
        }

        if (! $payload) {
            // No se registra escaneo: un QR mal formado o con firma invalida
            // puede ser cualquier cosa, no un intento de acceso atribuible.
            return response()->json([
                'result' => 'invalid',
                'message' => 'QR no valido',
            ]);
        }

        $ticket = DB::table('tickets_tickets')
            ->leftJoin('tickets_event_ticket_types as tt', 'tickets_tickets.ticket_type_id', '=', 'tt.id')
            ->leftJoin('tickets_orders as o', 'tickets_tickets.order_id', '=', 'o.id')
            ->where('tickets_tickets.uuid', $payload['uuid'])
            ->select([
                'tickets_tickets.*',
                'tt.name as type_name',
                'tt.wristband_color as type_wristband_color',
                'tt.wristband_label',
                'o.buyer_name', 'o.status as order_status',
            ])
            ->first();

        if (! $ticket) {
            return $this->log($eventId, null, 'invalid', 'UUID no encontrado', [
                'result' => 'invalid',
                'message' => 'Entrada no encontrada',
            ]);
        }

        if ((int) $ticket->event_id !== $eventId) {
            return $this->log($eventId, (int) $ticket->id, 'wrong_event', null, [
                'result' => 'wrong_event',
                'message' => 'Esta entrada es para otro evento',
            ]);
        }

        if ($ticket->order_status !== 'paid') {
            return $this->log($eventId, (int) $ticket->id, 'invalid', 'Orden no pagada', [
                'result' => 'invalid',
                'message' => 'La entrada no esta pagada',
            ]);
        }

        if ($ticket->status === 'cancelled') {
            return $this->log($eventId, (int) $ticket->id, 'invalid', 'Cancelada', [
                'result' => 'invalid',
                'message' => 'Entrada cancelada',
            ]);
        }

        $wristband = [
            'color' => $ticket->type_wristband_color ?? null,
            'label' => $ticket->wristband_label ?? null,
        ];

        if ($ticket->status === 'used') {
            return $this->log($eventId, (int) $ticket->id, 'used', null, [
                'result' => 'used',
                'message' => 'Esta entrada ya fue utilizada',
                'ticket' => [
                    'id' => $ticket->id,
                    'type_name' => $ticket->type_name,
                    'buyer_name' => $ticket->buyer_name,
                    'used_at' => $ticket->used_at,
                ],
                'wristband_color' => $wristband['color'],
                'wristband_label' => $wristband['label'],
            ]);
        }

        try {
            DB::transaction(function () use ($ticket, $scanner, $wristband) {
                /*
                | El where('status', 'valid') es lo que hace de cerrojo: si dos
                | puertas leen el mismo QR a la vez, solo una actualiza y la otra
                | tiene que tomarse el camino de "ya usada". Sin esa condicion,
                | las dos marcaban used y la segunda entrada pasaba sin detectar
                | el reuso.
                */
                $claimed = DB::table('tickets_tickets')
                    ->where('id', $ticket->id)
                    ->where('status', 'valid')
                    ->update([
                        'status' => 'used',
                        'used_at' => now(),
                        'used_by_scanner_id' => $scanner?->id,
                        'wristband_given' => true,
                        'wristband_color' => $wristband['color'],
                        'updated_at' => now(),
                    ]);

                if (! $claimed) {
                    throw ValidationException::withMessages([
                        'qr_payload' => 'Esta entrada ya fue utilizada',
                    ]);
                }

                DB::table('tickets_scans')->insert([
                    'ticket_id' => $ticket->id,
                    'event_id' => $ticket->event_id,
                    'scanner_user_id' => $scanner?->id,
                    'scanned_at' => now(),
                    'result' => 'valid',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (ValidationException $e) {
            // Perdimos la carrera por el boleto: la respuesta tiene que ser la
            // misma que da el chequeo de "used" de arriba, incluido el escaneo
            // registrado, para que el historial cuente los dos intentos.
            $fresh = DB::table('tickets_tickets')->where('id', $ticket->id)->first();

            return $this->log($eventId, (int) $ticket->id, 'used', $e->getMessage(), [
                'result' => 'used',
                'message' => 'Esta entrada ya fue utilizada',
                'ticket' => [
                    'id' => $ticket->id,
                    'type_name' => $ticket->type_name,
                    'buyer_name' => $ticket->buyer_name,
                    'used_at' => $fresh?->used_at ?? null,
                ],
                'wristband_color' => $wristband['color'],
                'wristband_label' => $wristband['label'],
            ]);
        }

        $eventName = DB::table('tickets_events')->where('id', $eventId)->value('name');

        Realtime::scanned($eventId, (int) $ticket->id, 'valid', $eventName, $ticket->type_name);

        return response()->json([
            'result' => 'valid',
            'message' => 'Entrada valida',
            'ticket' => [
                'id' => $ticket->id,
                'type_name' => $ticket->type_name,
                'buyer_name' => $ticket->buyer_name,
            ],
            'wristband_color' => $wristband['color'],
            'wristband_label' => $wristband['label'],
        ]);
    }

    /**
     * Listado de escaneos, para la seccion Escaneos del panel.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('tickets_scans as s')
            ->leftJoin('tickets_events as e', 's.event_id', '=', 'e.id')
            ->leftJoin('users as u', 's.scanner_user_id', '=', 'u.id')
            ->leftJoin('tickets_tickets as t', 's.ticket_id', '=', 't.id')
            ->leftJoin('tickets_event_ticket_types as tt', 't.ticket_type_id', '=', 'tt.id')
            ->select([
                's.id', 's.ticket_id', 's.event_id', 's.scanned_at', 's.result', 's.notes',
                'e.name as event_name',
                'u.name as scanner_name',
                'tt.name as ticket_type_name',
            ]);

        // El historial del cajero queda limitado a sus eventos asignados,
        // igual que la lista de eventos y el escaneo.
        if ($request->user()?->isCashier()) {
            $query->whereIn('s.event_id', $request->user()->assignedEventIds());
        }

        if ($eventId = $request->query('event_id')) {
            $query->where('s.event_id', (int) $eventId);
        }

        if ($result = $request->query('result')) {
            $query->where('s.result', $result);
        }

        return response()->json($query->orderByDesc('s.id')->paginate(30));
    }

    /**
     * Normaliza un texto a uuid canonico con guiones si tiene forma de uuid
     * (con o sin guiones, mayusculas o minusculas). Devuelve null si no lo es.
     */
    private static function looseUuid(string $raw): ?string
    {
        $hex = strtolower(str_replace('-', '', $raw));

        if (! preg_match('/^[0-9a-f]{32}$/', $hex)) {
            return null;
        }

        return substr($hex, 0, 8).'-'
            .substr($hex, 8, 4).'-'
            .substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'
            .substr($hex, 20);
    }

    /**
 * Registra el escaneo y devuelve la respuesta al lector. Los escaneos con
     * ticket_id nulo son los que no corresponden a ningun boleto.
     */
    private function log(
        int $eventId,
        ?int $ticketId,
        string $result,
        ?string $notes,
        array $response,
    ): JsonResponse {
        DB::table('tickets_scans')->insert([
            'ticket_id' => $ticketId,
            'event_id' => $eventId,
            'scanner_user_id' => request()->user()?->id,
            'scanned_at' => now(),
            'result' => $result,
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventName = DB::table('tickets_events')->where('id', $eventId)->value('name');

        Realtime::scanned($eventId, $ticketId, $result, $eventName);

        return response()->json($response);
    }
}
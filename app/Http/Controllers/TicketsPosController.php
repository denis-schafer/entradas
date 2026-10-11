<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\TicketOrderService;
use App\Support\QrRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS de la caja: venta en efectivo.
 *
 * Solo efectivo por ahora: la venta se cierra en el momento, se marca pagada y
 * se emite el comprobante con los QR. El vendedor trabaja acotado a los eventos
 * que tiene asignados; el administrador ve todos.
 */
class TicketsPosController extends Controller
{
    public function __construct(
        private TicketOrderService $orders,
        private OrderPaymentService $payments,
    ) {}

    /**
     * Eventos publicados sobre los que el usuario puede vender.
     */
    public function events(Request $request): JsonResponse
    {
        $query = DB::table('tickets_events')
            ->where('status', 'published')
            ->orderBy('starts_at')
            ->select(['id', 'name', 'starts_at', 'location']);

        $this->scopeEvents($request, $query, 'id');

        return response()->json($query->get());
    }

    /**
     * Tipos de entrada habilitados de un evento, para armar el carrito.
     */
    public function ticketTypes(Request $request, int $eventId): JsonResponse
    {
        $event = DB::table('tickets_events')->where('id', $eventId)->first();

        if (! $event || ! $this->eventInScope($request->user(), $eventId)) {
            return response()->json(['message' => 'Evento no disponible'], 404);
        }

        $types = DB::table('tickets_event_ticket_types')
            ->where('event_id', $eventId)
            ->where('enable', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'description', 'price', 'stock', 'sold_count', 'max_per_order']);

        return response()->json([
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'starts_at' => $event->starts_at,
                'location' => $event->location,
            ],
            'ticket_types' => $types,
        ]);
    }

    /**
     * Busca compradores por DNI (exacto) o por nombre/DNI (parcial). El POS
     * exige resolver el comprador antes de vender.
     */
    public function buyers(Request $request): JsonResponse
    {
        $query = User::query()
            ->where('is_admin', false)
            ->select(['id', 'name', 'dni', 'phone', 'email']);

        $dni = $request->query('dni') ? $this->normalizeDni((string) $request->query('dni')) : '';

        if ($dni !== '') {
            $query->where('dni', $dni);
        } elseif ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('dni', 'like', "%{$search}%");
            });
        } else {
            return response()->json([]);
        }

        return response()->json($query->orderBy('name')->limit(20)->get());
    }

    /**
     * Alta de comprador desde la caja. Se identifica por DNI y la contrasena
     * por defecto es el mismo DNI; el comprador la puede cambiar luego desde el
     * portal.
     */
    public function storeBuyer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'dni' => 'required|string|max:30',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150|unique:users,email',
        ]);

        $dni = $this->normalizeDni($validated['dni']);

        if (strlen($dni) < 7 || strlen($dni) > 10) {
            throw ValidationException::withMessages([
                'dni' => 'El DNI debe tener entre 7 y 10 digitos.',
            ]);
        }

        if (DB::table('users')->where('dni', $dni)->exists()) {
            throw ValidationException::withMessages([
                'dni' => 'Ya existe una cuenta con ese DNI.',
            ]);
        }

        $id = DB::table('users')->insertGetId([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'dni' => $dni,
            'phone' => $validated['phone'] ?? null,
            // Contrasena inicial = DNI. El comprador la cambia desde el portal.
            'password' => bcrypt($dni),
            'is_admin' => false,
            'enable' => true,
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $id,
            'name' => $validated['name'],
            'dni' => $dni,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'message' => 'Comprador creado',
        ], 201);
    }

    /**
     * Cierra una venta en efectivo: crea la orden, la marca pagada y devuelve
     * el comprobante.
     */
    public function sell(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'event_id' => 'required|integer',
            'buyer_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.ticket_type_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1',
        ]);

        $event = DB::table('tickets_events')->where('id', $validated['event_id'])->first();

        if (! $event || ! $this->eventInScope($actor, (int) $event->id)) {
            return response()->json(['message' => 'Evento no disponible'], 422);
        }

        $buyer = User::where('id', $validated['buyer_id'])->where('is_admin', false)->first();

        if (! $buyer) {
            throw ValidationException::withMessages([
                'buyer_id' => 'Comprador no valido.',
            ]);
        }

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
                    'message' => "Maximo por venta para {$type->name}: {$type->max_per_order}",
                ], 422);
            }

            $items[] = [
                'ticket_type_id' => (int) $type->id,
                'qty' => (int) $item['qty'],
                'unit_price' => round((float) $type->price, 2),
            ];
        }

        $result = $this->orders->createOrder($event, [
            'id' => $buyer->id,
            'name' => $buyer->name,
            'email' => $buyer->email,
            'dni' => $buyer->dni,
            'phone' => $buyer->phone,
        ], $items, [
            'payment_mode' => 'single',
            'installment_count' => 1,
            'seller_user_id' => $actor?->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        $order = DB::table('tickets_orders')->where('id', $result['id'])->first();

        // El efectivo se acredita en el acto: no hay webhook ni espera.
        $this->payments->markPaid(
            $order,
            'efectivo-'.$result['id'],
            ['transaction_amount' => $result['total']],
            'efectivo',
        );

        return response()->json($this->receiptPayload($result['id']), 201);
    }

    /**
     * Historial de ventas del POS. El vendedor ve las suyas; el administrativo
     * y el administrador ven las de sus eventos.
     */
    public function sales(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = DB::table('tickets_orders as o')
            ->join('tickets_events as e', 'e.id', '=', 'o.event_id')
            ->where('o.payment_method', 'efectivo')
            ->orderByDesc('o.id')
            ->select([
                'o.id', 'o.total', 'o.status', 'o.created_at', 'o.paid_at',
                'o.buyer_name', 'o.buyer_dni',
                'e.name as event_name',
                DB::raw('(SELECT COUNT(*) FROM tickets_tickets t WHERE t.order_id = o.id) as ticket_count'),
            ]);

        if ($actor && $actor->isSeller()) {
            $query->where('o.seller_user_id', $actor->id);
        }

        $this->scopeEvents($request, $query, 'o.event_id');

        return response()->json($query->limit(100)->get());
    }

    /**
     * Comprobante de una venta: datos de la orden, el evento, el comprador y
     * las entradas con la URL del QR para imprimir.
     */
    public function receipt(Request $request, int $id): JsonResponse
    {
        $order = DB::table('tickets_orders')->where('id', $id)->first();

        if (! $order) {
            return response()->json(['message' => 'Venta no encontrada'], 404);
        }

        $actor = $request->user();

        if (! $this->canSeeOrder($actor, $order)) {
            return response()->json(['message' => 'Venta no encontrada'], 404);
        }

        return response()->json($this->receiptPayload($id));
    }

    /**
     * QR de un boleto vendido. El cajero no lo usa; lo imprime el vendedor
     * desde el comprobante.
     */
    public function ticketQr(Request $request, int $ticketId)
    {
        $request->validate(['size' => 'nullable|integer|min:120|max:1024']);

        $ticket = DB::table('tickets_tickets')->where('id', $ticketId)->first();

        if (! $ticket || empty($ticket->qr_payload) || ! $this->eventInScope($request->user(), (int) $ticket->event_id)) {
            abort(404);
        }

        return response(QrRenderer::svg($ticket->qr_payload, (int) $request->query('size', 400)), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Arma la respuesta del comprobante. La usan la venta recien cerrada y la
     * consulta posterior.
     */
    private function receiptPayload(int $orderId): array
    {
        $order = DB::table('tickets_orders as o')
            ->leftJoin('tickets_events as e', 'e.id', '=', 'o.event_id')
            ->leftJoin('users as s', 's.id', '=', 'o.seller_user_id')
            ->where('o.id', $orderId)
            ->select([
                'o.id', 'o.public_token', 'o.total', 'o.subtotal', 'o.status',
                'o.payment_method', 'o.paid_at', 'o.created_at',
                'o.buyer_name', 'o.buyer_dni', 'o.buyer_phone', 'o.buyer_email',
                'e.id as event_id', 'e.name as event_name', 'e.starts_at', 'e.location',
                's.name as seller_name',
            ])
            ->first();

        $tickets = DB::table('tickets_tickets as t')
            ->leftJoin('tickets_event_ticket_types as tt', 'tt.id', '=', 't.ticket_type_id')
            ->where('t.order_id', $orderId)
            ->orderBy('t.id')
            ->get(['t.id', 't.uuid', 't.status', 'tt.name as type_name']);

        foreach ($tickets as $ticket) {
            $ticket->qr_url = "/tickets-admin/pos/tickets/{$ticket->id}/qr.svg";
        }

        return [
            'order' => $order,
            'tickets' => $tickets,
        ];
    }

    /**
     * @return bool  true si el usuario puede ver la venta (suya o de su evento)
     */
    private function canSeeOrder(?User $actor, object $order): bool
    {
        if (! $actor) {
            return false;
        }

        if ($actor->isStaffAdmin()) {
            return true;
        }

        if ((int) $order->seller_user_id === (int) $actor->id) {
            return true;
        }

        return $this->eventInScope($actor, (int) $order->event_id);
    }

    private function eventInScope(?User $actor, int $eventId): bool
    {
        if (! $actor) {
            return false;
        }

        if ($actor->isStaffAdmin()) {
            return true;
        }

        return in_array($eventId, $actor->assignedEventIds(), true);
    }

    /**
     * Acota una query a los eventos del usuario. El administrador no se limita.
     * Sin eventos asignados, no deja pasar ninguna fila.
     */
    private function scopeEvents(Request $request, $query, string $column): void
    {
        $actor = $request->user();

        if ($actor && ! $actor->isStaffAdmin()) {
            $query->whereIn($column, $actor->assignedEventIds() ?: [0]);
        }
    }

    private function normalizeDni(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }
}

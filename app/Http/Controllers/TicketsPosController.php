<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\Payments\EventPaymentMethods;
use App\Services\Payments\MercadoPagoCheckout;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\PaymentSetupException;
use App\Services\TicketOrderService;
use App\Support\PaymentMethodFilter;
use App\Support\QrRenderer;
use App\Support\TicketNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS de la caja.
 *
 * El vendedor cobra en efectivo (la venta se cierra en el acto) o genera un
 * link de pago (Checkout Pro de MP o codigo Multipago) para mandarle al
 * comprador; en ese caso la orden queda pendiente hasta que el webhook la
 * acredita. El operador trabaja acotado a los eventos que tiene asignados y a
 * los medios de pago que tiene habilitados; el administrador ve todo.
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
     * Cierra una venta desde el POS.
     *
     * En efectivo la orden se marca pagada en el acto y se devuelve el
     * comprobante con los QR. Con un medio de pago la orden queda pendiente y
     * se devuelve el link de pago (Checkout Pro de MP o codigo Multipago) para
     * que el vendedor se lo mande al comprador; el link de la entrada recien
     * queda disponible cuando el webhook acredita el pago.
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
            'payment_method' => 'nullable|string|in:efectivo,mercadopago,multipago',
            'payment_method_variant' => 'nullable|string|in:qr,button',
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

        $items = $this->prepareItems((int) $event->id, $validated['items']);

        if (is_string($items)) {
            return response()->json(['message' => $items], 422);
        }

        $method = $validated['payment_method'] ?? 'efectivo';
        $variant = $validated['payment_method_variant'] ?? null;

        if ($method !== 'efectivo') {
            $denied = $this->chargingDenied($actor, (int) $event->id, $method, $variant);

            if ($denied !== null) {
                return response()->json(['message' => $denied], 422);
            }
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
            'seller_name' => $actor?->name,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        if ($method === 'efectivo') {
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

        // La orden queda pendiente; dejamos registrado el medio elegido.
        DB::table('tickets_orders')->where('id', $result['id'])->update([
            'payment_method' => $method,
            'updated_at' => now(),
        ]);

        try {
            $payment = $this->buildPayment((int) $result['id'], $method, $variant, false);
        } catch (PaymentSetupException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error: '.$e->getMessage()], 500);
        }

        $payload = $this->receiptPayload($result['id']);
        $payload['payment'] = $payment;

        return response()->json($payload, 201);
    }

    /**
     * Medios con los que ESTE operador puede cobrar en un evento. El efectivo
     * va siempre primero; los medios de pago solo si el evento los tiene
     * habilitados y el operador tiene permiso (el admin, sin restriccion).
     */
    public function paymentMethods(Request $request, int $eventId): JsonResponse
    {
        $actor = $request->user();
        $event = DB::table('tickets_events')->where('id', $eventId)->first();

        if (! $event || ! $this->eventInScope($actor, $eventId)) {
            return response()->json(['message' => 'Evento no disponible'], 404);
        }

        $methods = [[
            'code' => 'efectivo',
            'variant' => null,
            'name' => 'Efectivo',
            'type' => 'cash',
            'subtitle' => 'Cobro inmediato en la caja',
        ]];

        $allowed = ($actor && $actor->isStaffAdmin()) ? null : ($actor ? $actor->allowedPaymentMethodCodes() : []);

        foreach (EventPaymentMethods::forEvent($eventId) as $method) {
            if ($allowed !== null && ! in_array($method['code'], $allowed, true)) {
                continue;
            }

            $methods[] = $method;
        }

        return response()->json(['methods' => $methods]);
    }

    /**
     * Link de pago de una venta pendiente (reenvio). Idempotente: reusa la
     * preferencia de MP ya creada y el codigo Multipago es deterministico.
     */
    public function paymentLink(Request $request, int $id): JsonResponse
    {
        $order = DB::table('tickets_orders')->where('id', $id)->first();

        if (! $order || ! $this->canSeeOrder($request->user(), $order)) {
            return response()->json(['message' => 'Venta no encontrada'], 404);
        }

        if ($order->status !== 'pending' || ! in_array((string) $order->payment_method, ['mercadopago', 'multipago'], true)) {
            return response()->json(['message' => 'Esta venta no tiene un link de pago pendiente.'], 422);
        }

        try {
            $payment = $this->buildPayment((int) $order->id, (string) $order->payment_method, null, true);
        } catch (PaymentSetupException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error: '.$e->getMessage()], 500);
        }

        $payload = $this->receiptPayload((int) $order->id);
        $payload['payment'] = $payment;

        return response()->json($payload);
    }

    /**
     * SVG del QR del codigo Multipago de una venta pendiente.
     */
    public function multipagoQrSvg(Request $request, int $id)
    {
        $order = DB::table('tickets_orders')->where('id', $id)->first();

        if (! $order || ! $this->canSeeOrder($request->user(), $order)) {
            abort(404);
        }

        if ($order->status !== 'pending' || $order->payment_method !== 'multipago') {
            abort(404);
        }

        try {
            $built = $this->multipagoBuild($order, null);
        } catch (PaymentSetupException) {
            abort(404);
        }

        $value = $built['content'] ?? $built['code'];

        return response(QrRenderer::svg($value, (int) $request->query('size', 400)), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    /**
     * Historial de ventas del POS. El vendedor ve las suyas; el administrativo
     * y el administrador ven las de sus eventos. Incluye las pendientes (link
     * de pago enviado) y las ya acreditadas.
     */
    public function sales(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = DB::table('tickets_orders as o')
            ->join('tickets_events as e', 'e.id', '=', 'o.event_id')
            ->whereNotNull('o.seller_user_id')
            ->orderByDesc('o.id')
            ->select([
                'o.id', 'o.total', 'o.status', 'o.created_at', 'o.paid_at',
                'o.payment_method', 'o.seller_name', 'o.buyer_name', 'o.buyer_dni', 'o.public_token',
                DB::raw('COALESCE(o.event_name, e.name) as event_name'),
                DB::raw('(SELECT COUNT(*) FROM tickets_tickets t WHERE t.order_id = o.id) as ticket_count'),
            ]);

        if ($actor && $actor->isSeller()) {
            $query->where('o.seller_user_id', $actor->id);
        }

        $this->scopeEvents($request, $query, 'o.event_id');

        PaymentMethodFilter::apply($query, $request->query('payment_method'), 'o.payment_method', 'o.payment_variant');

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

        // Debajo del QR va el numero de la entrada, para el comprobante impreso.
        $caption = TicketNumber::caption($ticket->number === null ? null : (int) $ticket->number, (int) $ticket->event_id);

        return response(QrRenderer::svg($ticket->qr_payload, (int) $request->query('size', 400), $caption), 200, [
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
                'o.event_id',
                DB::raw('COALESCE(o.event_name, e.name) as event_name'),
                DB::raw('COALESCE(o.event_starts_at, e.starts_at) as starts_at'),
                DB::raw('COALESCE(o.event_location, e.location) as location'),
                DB::raw('COALESCE(o.seller_name, s.name) as seller_name'),
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
     * Valida los items del carrito y arma el array para TicketOrderService.
     * Devuelve un string con el mensaje de error si algo no esta disponible.
     *
     * @param  array<int,array{ticket_type_id:int|string,qty:int|string}>  $items
     * @return array<int,array{ticket_type_id:int,qty:int,unit_price:float}>|string
     */
    private function prepareItems(int $eventId, array $items)
    {
        $prepared = [];

        foreach ($items as $item) {
            $type = DB::table('tickets_event_ticket_types')
                ->where('event_id', $eventId)
                ->where('id', $item['ticket_type_id'])
                ->where('enable', true)
                ->first();

            if (! $type) {
                return "Tipo de entrada {$item['ticket_type_id']} no disponible";
            }

            if ($type->stock !== null && ($type->sold_count + (int) $item['qty']) > $type->stock) {
                return "Stock insuficiente para {$type->name}";
            }

            if ((int) $item['qty'] > $type->max_per_order) {
                return "Maximo por venta para {$type->name}: {$type->max_per_order}";
            }

            $prepared[] = [
                'ticket_type_id' => (int) $type->id,
                'qty' => (int) $item['qty'],
                'unit_price' => round((float) $type->price, 2),
            ];
        }

        return $prepared;
    }

    /**
     * Devuelve el motivo por el que el operador no puede cobrar con ese medio,
     * o null si puede. El efectivo no pasa por aca (siempre disponible).
     */
    private function chargingDenied(?User $actor, int $eventId, string $method, ?string $variant): ?string
    {
        if (! $actor) {
            return 'No autenticado.';
        }

        $available = EventPaymentMethods::forEvent($eventId);
        $codes = array_unique(array_column($available, 'code'));

        if (! in_array($method, $codes, true)) {
            return 'Ese medio de pago no esta disponible para este evento.';
        }

        if ($method === 'multipago') {
            $variants = [];

            foreach ($available as $m) {
                if ($m['code'] === 'multipago') {
                    $variants[] = $m['variant'];
                }
            }

            if (! in_array($variant, $variants, true)) {
                return 'Elegi como cobrar con Multipago (QR o boton de pago).';
            }
        }

        if (! $actor->isStaffAdmin() && ! in_array($method, $actor->allowedPaymentMethodCodes(), true)) {
            return 'Tu usuario no tiene habilitado ese medio de pago.';
        }

        return null;
    }

    /**
     * Arma los datos de cobro de una orden no-efectivo: link de Checkout Pro de
     * MP o codigo Multipago. Con $reuse intenta no generar uno nuevo.
     *
     * @return array<string,mixed>
     *
     * @throws PaymentSetupException
     */
    private function buildPayment(int $orderId, string $method, ?string $variant, bool $reuse): array
    {
        $order = DB::table('tickets_orders')->where('id', $orderId)->first();

        if ($method === 'mercadopago') {
            $preference = app(MercadoPagoCheckout::class)->preferenceForOrder($order, $reuse);

            return [
                'method' => 'mercadopago',
                'variant' => null,
                'status' => 'pending',
                'link' => $preference['init_point'] ?: $preference['sandbox_init_point'],
                'preference_id' => $preference['preference_id'],
                'code' => null,
                'qr_url' => null,
            ];
        }

        // Multipago: la variante (qr o boton) decide el formato del pago. El
        // QR nativo es deterministico dentro del dia (importe + vencimiento).
        $built = $this->multipagoBuild($order, $variant);

        return [
            'method' => 'multipago',
            'variant' => $built['variant'],
            'status' => 'pending',
            'link' => $built['link'],
            'preference_id' => $built['reference'],
            'code' => $built['code'],
            'qr_url' => "/tickets-admin/pos/orders/{$orderId}/multipago-qr.svg",
        ];
    }

    /**
     * Arma el pago Multipago de una orden y persiste el medio, la variante y la
     * referencia (identificador del QR o codigo PUM) para que el webhook pueda
     * resolver el pago. Si no se indica variante, se reusa la guardada (o QR).
     *
     * @return array{variant:string,reference:string,code:string,content:?string,link:?string}
     *
     * @throws PaymentSetupException
     */
    private function multipagoBuild(object $order, ?string $variant): array
    {
        $gateway = PaymentGatewayRegistry::for('multipago');
        $variant = $this->multipagoVariant($order, $variant);
        $orderId = (int) $order->id;
        $eventId = (int) $order->event_id;
        $due = now()->addHours(24)->toDateString();

        if ($variant === 'button') {
            $reference = $gateway->pumCode($orderId, $due, (float) $order->total, $eventId);
            $content = null;
            $link = $gateway->pumUrl($reference);
        } else {
            $reference = $gateway->customerIdentifier($orderId, $eventId);
            $content = $gateway->qrContent($orderId, $due, (float) $order->total, $eventId);
            $link = null;
        }

        DB::table('tickets_orders')->where('id', $orderId)->update([
            'payment_method' => 'multipago',
            'payment_variant' => $variant,
            'payment_reference' => $reference,
            'updated_at' => now(),
        ]);

        return [
            'variant' => $variant,
            'reference' => $reference,
            'code' => $reference,
            'content' => $content,
            'link' => $link,
        ];
    }

    /**
     * Variante efectiva de Multipago: la pedida, si no la guardada en la orden,
     * y por defecto el QR.
     */
    private function multipagoVariant(object $order, ?string $variant): string
    {
        if (in_array($variant, ['qr', 'button'], true)) {
            return $variant;
        }

        return (string) ($order->payment_variant ?? '') === 'button' ? 'button' : 'qr';
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

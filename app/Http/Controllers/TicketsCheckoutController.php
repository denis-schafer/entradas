<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentGatewayRegistry;
use App\Support\QrRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
| Seleccion de medio de pago en el portal de compras.
|
| Al crear la orden el comprador elige con que medio pagar. El orden de esta
| pantalla:
|   - methods(): lista los medios habilitados para el evento, con tipo de flujo
|     ('redirect' para Checkout Pro de MP, 'qr' para Multipago).
|   - multipagoCode(): PUM de la orden (lo que el comprador paga).
|   - multipagoQrSvg(): QR del PUM para mostrar en el celu.
|
| El medio solo se fija en la orden cuando el comprador lo elige (payment_method
| y payment_reference). El cobro llega despues, por webhook o validacion, y
| OrderPaymentService::markPaid() es quien termina de marcarla.
*/
class TicketsCheckoutController extends Controller
{
    public function methods(Request $request, int $orderId): JsonResponse
    {
        $buyer = $request->attributes->get('tickets_portal_user');

        if (! $buyer) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $order = DB::table('tickets_orders')
            ->where('id', $orderId)
            ->where('buyer_user_id', $buyer['id'])
            ->where('status', 'pending')
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada o ya pagada'], 404);
        }

        $methods = [];

        foreach (PaymentGatewayRegistry::all() as $gateway) {
            if (! $gateway->eventEnabled((int) $order->event_id)) {
                continue;
            }

            $type = $gateway->code() === 'mercadopago' ? 'redirect' : 'qr';

            // Solo se ofrece el medio si ademas hay con que cobrar.
            if ($gateway->code() === 'mercadopago') {
                if (empty($gateway->eventToken((int) $order->event_id))) {
                    continue;
                }
            } elseif ($gateway->code() === 'multipago') {
                if (trim((string) $gateway->methodConfig()['bersacode'] ?? '') === ''
                    || trim((string) $gateway->methodConfig()['username'] ?? '') === '') {
                    continue;
                }
            }

            $methods[] = [
                'code' => $gateway->code(),
                'name' => $gateway->name(),
                'type' => $type,
            ];
        }

        return response()->json(['methods' => $methods, 'order_id' => (int) $order->id]);
    }

    /**
     * PUM (codigo de barras Muestro de Multipago) para la orden.
     */
    public function multipagoCode(Request $request, int $orderId): JsonResponse
    {
        $order = $this->pendingOrder($request, $orderId);

        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        $gateway = $this->gatewayOrFail('multipago');

        if (! $gateway->eventEnabled((int) $order->event_id)) {
            return response()->json(['message' => 'Multipago no disponible para este evento'], 422);
        }

        // Multipago cobra por vencimiento: siempre 24 horas desde ahora.
        $due = now()->addHours(24)->toDateString();

        $code = $gateway->pumCode((int) $order->id, $due, (float) $order->total);

        if (DB::table('tickets_orders')->where('id', $order->id)->value('payment_method') !== 'multipago') {
            DB::table('tickets_orders')->where('id', $order->id)->update([
                'payment_method' => 'multipago',
                'payment_reference' => $code,
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'code' => $code,
            'due' => $due,
            'amount' => (float) $order->total,
            'url' => $gateway->pumUrl($code),
        ]);
    }

    /**
     * SVG del QR del codigo Multipago para la orden.
     */
    public function multipagoQrSvg(Request $request, int $orderId)
    {
        $code = $this->multipagoCode($request, $orderId);

        if ($code->getStatusCode() !== 200) {
            abort(404);
        }

        $pum = $code->getData()->code;

        return response(QrRenderer::svg($pum, (int) $request->query('size', 400)), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    private function pendingOrder(Request $request, int $orderId): ?object
    {
        $buyer = $request->attributes->get('tickets_portal_user');

        if (! $buyer) {
            return null;
        }

        return DB::table('tickets_orders')
            ->where('id', $orderId)
            ->where('buyer_user_id', $buyer['id'])
            ->where('status', 'pending')
            ->first();
    }

    private function gatewayOrFail(string $code)
    {
        $gateway = PaymentGatewayRegistry::for($code);

        if (! $gateway) {
            abort(404);
        }

        return $gateway;
    }
}
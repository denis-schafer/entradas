<?php

namespace App\Http\Controllers;

use App\Services\OrderExpiry;
use App\Services\Payments\EventPaymentMethods;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\PaymentSetupException;
use App\Support\OrderWindow;
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
|     ('redirect' para Checkout Pro de MP, 'multipago-qr'/'multipago-button').
|   - multipagoCode(): pago Multipago de la orden (QR nativo o codigo PUM).
|   - multipagoQrSvg(): QR del pago para mostrar en el celu.
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
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'La reserva ya no esta disponible. Inicia una compra nueva.'], 410);
        }

        if ($rejection = $this->rejectIfNotPayable($order)) {
            return $rejection;
        }

        return response()->json([
            'methods' => EventPaymentMethods::forEvent((int) $order->event_id),
            'order_id' => (int) $order->id,
            'expires_at' => OrderWindow::deadline($order)->toIso8601String(),
        ]);
    }

    /**
     * Pago Multipago de la orden: QR nativo o codigo PUM (boton de pago). La
     * variante llega por query (?variant=qr|button); por defecto el QR.
     */
    public function multipagoCode(Request $request, int $orderId): JsonResponse
    {
        $order = $this->pendingOrder($request, $orderId);

        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        if ($rejection = $this->rejectIfNotPayable($order)) {
            return $rejection;
        }

        $gateway = $this->gatewayOrFail('multipago');

        if (! $gateway->eventEnabled((int) $order->event_id)) {
            return response()->json(['message' => 'Multipago no disponible para este evento'], 422);
        }

        $variant = $request->query('variant') === 'button' ? 'button' : 'qr';

        try {
            $built = $this->multipagoBuild($order, $variant);
        } catch (PaymentSetupException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        return response()->json([
            'code' => $built['code'],
            'variant' => $built['variant'],
            'due' => $built['due'],
            'amount' => (float) $order->total,
            'url' => $built['link'],
        ]);
    }

    /**
     * SVG del QR Multipago para la orden. Codifica el contenido nativo (variante
     * QR) o el codigo PUM (boton de pago), segun la variante guardada.
     */
    public function multipagoQrSvg(Request $request, int $orderId)
    {
        $order = $this->pendingOrder($request, $orderId);

        if (! $order || $order->payment_method !== 'multipago') {
            abort(404);
        }

        if (! OrderWindow::isPayable($order)) {
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
     * Arma el pago Multipago y persiste medio, variante y referencia.
     *
     * @return array{variant:string,due:string,code:string,content:?string,link:?string}
     *
     * @throws PaymentSetupException
     */
    private function multipagoBuild(object $order, ?string $variant): array
    {
        $gateway = PaymentGatewayRegistry::for('multipago');
        $variant = $this->multipagoVariant($order, $variant);
        $orderId = (int) $order->id;
        $eventId = (int) $order->event_id;
        $due = OrderWindow::deadline($order)->toDateString();

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
            'due' => $due,
            'code' => $reference,
            'content' => $content,
            'link' => $link,
        ];
    }

    /**
     * Variante efectiva de Multipago (la pedida, la guardada, o QR).
     */
    private function multipagoVariant(object $order, ?string $variant): string
    {
        if (in_array($variant, ['qr', 'button'], true)) {
            return $variant;
        }

        return (string) ($order->payment_variant ?? '') === 'button' ? 'button' : 'qr';
    }

    /**
     * Si la orden ya no se puede pagar, la vence (o la deja lista para volver a
     * comprar) y devuelve la respuesta. Null si todavia se puede pagar.
     */
    private function rejectIfNotPayable(object $order): ?JsonResponse
    {
        if (OrderWindow::isPayable($order)) {
            return null;
        }

        $expiry = app(OrderExpiry::class);

        if (OrderWindow::isPastDeadline($order) || ! OrderWindow::typesAreAvailable($order)) {
            $expiry->expireIfPastDue($order);

            return response()->json(['message' => 'La reserva vencio. Inicia una compra nueva.'], 410);
        }

        $expiry->expireForPriceChange($order);

        return response()->json(['message' => 'El precio de las entradas cambio. Inicia una compra nueva.'], 409);
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
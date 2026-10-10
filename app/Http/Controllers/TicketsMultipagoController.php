<?php

namespace App\Http\Controllers;

use App\Services\OrderPaymentService;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TicketsMultipagoController extends Controller
{
    /**
     * Webhook de Multipago. GET o POST (como los envia ISPCube), si sin sesion.
     *
     * Respuesta en texto plano: "insertado" | "ya ingresado" |
     * "cliente no encontrado" | error. Los duplicados se controlan por
     * `identifier` (id del cobro en Multipago), igual que en el chequeo masivo.
     */
    public function webhook(Request $request, int $eventId, string $key): Response
    {
        $gateway = PaymentGatewayRegistry::for('multipago');

        if (! $gateway->webhookKeyMatches($eventId, $key)) {
            Log::warning('[Multipago Webhook] key invalida', ['event_id' => $eventId]);

            return $this->plain('Error en webhooks_key', 403);
        }

        if (! $gateway->eventEnabled($eventId)) {
            return $this->plain('Modulo deshabilitado', 403);
        }

        $payload = $request->isMethod('post')
            ? ($request->json()->all() ?: $request->all())
            : $request->query();

        $cobro = is_array($payload['cobro'] ?? null) ? $payload['cobro'] : $payload;

        if (! is_array($cobro)) {
            return $this->plain('Payload invalido', 422);
        }

        $normalized = $gateway->normalizeWebhook($cobro);

        if (! $normalized['ok']) {
            return $this->plain($normalized['message'], 422);
        }

        $order = DB::table('tickets_orders')->where('id', $normalized['order_id'])->first();

        if (! $order) {
            Log::warning('[Multipago Webhook] orden no encontrada', [
                'event_id' => $eventId,
                'order_id' => $normalized['order_id'],
            ]);

            return $this->plain('cliente no encontrado', 404);
        }

        // La orden pertenece al evento de la URL: nunca validar un pago contra
        // el evento equivocado solo porque el customer_id coincida.
        if ((int) $order->event_id !== $eventId) {
            Log::warning('[Multipago Webhook] orden de otro evento', [
                'event_id' => $eventId,
                'order_id' => $order->id,
                'order_event_id' => $order->event_id,
            ]);

            return $this->plain('orden de otro evento', 409);
        }

        if ($normalized['amount'] !== null) {
            $diff = abs((float) $order->total - $normalized['amount']);

            if ($diff > 0.01) {
                Log::warning('[Multipago Webhook] importe no coincide', [
                    'order_id' => $order->id,
                    'esperado' => $order->total,
                    'llegado' => $normalized['amount'],
                ]);

                return $this->plain('importe no coincide', 422);
            }
        }

        try {
            $result = app(OrderPaymentService::class)->markPaid(
                $order,
                (string) $normalized['payment_id'],
                ['transaction_amount' => $normalized['amount']],
                'multipago'
            );

            return $this->plain($result['changed'] ? 'insertado' : 'ya ingresado');
        } catch (\Throwable $e) {
            Log::error('[Multipago Webhook] exception', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return $this->plain('error', 500);
        }
    }

    private function plain(string $text, int $status = 200): Response
    {
        return response($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Integracion con Multipago Online.
 *
 * Tres piezas (ver referencia en C:\Users\Denis\Downloads\Multipago):
 *   - PUM: codigo de barras de 28/32 digitos que abre el pago. El mismo codigo
 *     se puede servir como QR. Composicion (con `large` = digitos de cliente):
 *         comercio(4) + cliente(large) + vencimiento ddmmaa(6) + importe sin
 *         coma(11) + DV modulo 10 (1).
 *   - Webhook en tiempo real: POST/GET con el JSON {customer_id, amount,
 *     identifier, fecha_pago}. Es UNA sola URL para todos los eventos (una
 *     cuenta de Multipago); se identifica por la key de la URL y se deduplica
 *     por identifier. El evento surge de la orden resuelta por customer_id.
 *   - Consulta masiva: GET consultar_deuda/{bersacode}/{desde}/{hasta} con
 *     Basic Auth; se usa para "Validar pagos" desde el modulo.
 *
 * El "cliente" de Multipago es nuestro order_id (identificador del pago),
 * convertido a codigo numerico con `large` digitos.
 */
class MultipagoGateway extends PaymentGateway
{
    public const API_BASE = 'https://scol2.multipago.com.ar/index.php/v1/ispcube/';

    public const PUM_URL = 'https://pum.multipago.com.ar/pago?flow=barcode&codigo_barra=';

    public function code(): string
    {
        return 'multipago';
    }

    public function name(): string
    {
        return 'Multipago';
    }

    public function secretKeys(): array
    {
        return ['bersacode', 'username', 'password', 'differentiator', 'webhook_key'];
    }

    public function large(): int
    {
        $large = (int) ($this->methodConfig()['large'] ?? 10);

        return max(1, min($large, 10));
    }

    public function bersacode(): string
    {
        return (string) ($this->methodConfig()['bersacode'] ?? '');
    }

    /**
     * Codigo de cliente (nuestro order_id) con los digitos que pide el convenio.
     */
    public function clientCode(int $orderId): string
    {
        return str_pad((string) $orderId, $this->large(), '0', STR_PAD_LEFT);
    }

    /**
     * Digito verificador modulo 10 del convenio Bersa/Multipago:
     * posiciones impares x3 + pares, completando al multiplo de 10.
     */
    public function digitVerifier(string $number): string
    {
        $impares = 0;
        $pares = 0;

        foreach (str_split($number) as $i => $digit) {
            if ($i % 2 === 0) {
                $impares += (int) $digit;
            } else {
                $pares += (int) $digit;
            }
        }

        $total = ($impares * 3) + $pares;
        $verificador = 0;

        while (($total + $verificador) % 10 !== 0) {
            $verificador++;
        }

        return (string) $verificador;
    }

    /**
     * Codigo PUM (boton / QR) para cobrar una orden con Multipago.
     *
     * @param  string  $dueYmd  fecha de vencimiento Y-m-d (siempre ahora + 24 h)
     */
    public function pumCode(int $orderId, string $dueYmd, float $importe): string
    {
        $bersacode = str_pad(trim($this->bersacode()), 4, '0', STR_PAD_LEFT);
        $cliente = $this->clientCode($orderId);
        $vencimiento = \DateTime::createFromFormat('Y-m-d', $dueYmd)->format('dmy');

        $importe = number_format($importe, 2, '.', '');
        $importe = str_pad(str_replace('.', '', $importe), 11, '0', STR_PAD_LEFT);

        $cuerpo = $bersacode.$cliente.$vencimiento.$importe;

        return $cuerpo.$this->digitVerifier($cuerpo);
    }

    public function pumUrl(string $pumCode): string
    {
        return self::PUM_URL.$pumCode;
    }

    /**
     * A partir del customer_id que reporta Multipago (codigo + DV), resuelve
     * nuestro order_id descartando el DV.
     */
    public function resolveOrderId(string $customerId): ?int
    {
        $customerId = trim($customerId);

        if ($customerId === '' || ! ctype_digit($customerId)) {
            return null;
        }

        $sinVerificador = substr($customerId, 0, -1);
        $orderId = (int) $sinVerificador;

        return $orderId > 0 ? $orderId : null;
    }

    /**
     * Key del webhook UNICO de Multipago (una sola cuenta/URL para todos los
     * eventos). Vive en la config global del metodo.
     */
    public function webhookKey(): string
    {
        return trim((string) ($this->methodConfig()['webhook_key'] ?? ''));
    }

    public function generateWebhookKey(): string
    {
        return Str::random(40);
    }

    /**
     * Devuelve la key del webhook, generandola y guardandola si aun no existe.
     */
    public function ensureWebhookKey(): string
    {
        $row = $this->methodRow();

        if (! $row) {
            return '';
        }

        $config = json_decode((string) ($row->config ?? '[]'), true) ?: [];

        if (! empty($config['webhook_key'])) {
            return (string) $config['webhook_key'];
        }

        $config['webhook_key'] = $this->generateWebhookKey();

        DB::table('tickets_payment_methods')->where('id', $row->id)->update([
            'config' => json_encode($config),
            'updated_at' => now(),
        ]);

        return (string) $config['webhook_key'];
    }

    /**
     * Rota la key del webhook (invalida la anterior).
     */
    public function regenerateWebhookKey(): string
    {
        $row = $this->methodRow();

        if ($row) {
            $config = json_decode((string) ($row->config ?? '[]'), true) ?: [];
            $config['webhook_key'] = $this->generateWebhookKey();

            DB::table('tickets_payment_methods')->where('id', $row->id)->update([
                'config' => json_encode($config),
                'updated_at' => now(),
            ]);
        }

        return $this->webhookUrl();
    }

    /**
     * URL publica unica del webhook. Una sola cuenta para todos los eventos.
     */
    public function webhookUrl(): string
    {
        $key = $this->ensureWebhookKey();

        if ($key === '') {
            return '';
        }

        return $this->webhookBase().'/tickets/multipago/webhook/'.$key;
    }

    /**
     * Valida que la key de la URL coincida con la global del metodo.
     */
    public function webhookKeyMatches(string $key): bool
    {
        $expected = $this->webhookKey();

        return $expected !== '' && hash_equals($expected, $key);
    }

    private function webhookBase(): string
    {
        $base = rtrim((string) DB::table('tickets_configs')
            ->where('name', 'payment_webhook_base')
            ->value('value'), '/');

        return $base !== '' ? $base : rtrim((string) config('app.url'), '/');
    }

    /**
     * Normaliza y valida un cobro del webhook.
     *
     * @return array{ok: bool, message: string, order_id: ?int, payment_id: ?string, amount: ?float}
     */
    public function normalizeWebhook(array $cobro): array
    {
        $orderId = $this->resolveOrderId((string) ($cobro['customer_id'] ?? ''));
        $paymentId = (string) ($cobro['identifier'] ?? '');
        $amount = is_numeric($cobro['amount'] ?? null) ? (float) $cobro['amount'] : null;

        if ($orderId === null) {
            return ['ok' => false, 'message' => 'customer_id invalido', 'order_id' => null, 'payment_id' => null, 'amount' => null];
        }

        if ($paymentId === '') {
            return ['ok' => false, 'message' => 'identifier vacio', 'order_id' => $orderId, 'payment_id' => null, 'amount' => $amount];
        }

        return [
            'ok' => true,
            'message' => 'ok',
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'amount' => $amount,
        ];
    }

    /**
     * Consulta de cobros a la API de Multipago (para Validar pagos).
     *
     * @return array{status: string, cobros: array}
     */
    public function consultCobros(?string $desde = null, ?string $hasta = null): array
    {
        $desde = $desde ?: now()->subDays((int) ($this->methodConfig()['days'] ?? 7))->toDateString();
        $hasta = $hasta ?: now()->toDateString();

        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $url = self::API_BASE.'consultar_deuda/'.$this->bersacode().'/'.$desde.'/'.$hasta;

        $response = Http::withBasicAuth($this->methodConfig()['username'] ?? '', $this->methodConfig()['password'] ?? '')
            ->withHeaders(['Content-Type' => 'application/json'])
            ->timeout(60)
            ->get($url);

        if ($response->failed()) {
            return ['status' => 'ERROR', 'cobros' => []];
        }

        try {
            $body = $response->json() ?: [];
        } catch (\Throwable) {
            $body = [];
        }

        return [
            'status' => (string) ($body['status'] ?? 'ERROR'),
            'cobros' => is_array($body['cobros'] ?? null) ? $body['cobros'] : [],
        ];
    }

    /**
     * Presenta la deuda de un cliente (POST obtener_deuda). Reemplaza la base
     * presentada para el comercio. Por ahora se usa en pruebas manuales.
     *
     * @return array{status: string, message: string}
     */
    public function presentDebt(int $orderId, string $dueYmd, float $importe): array
    {
        $url = self::API_BASE.'obtener_deuda';
        $identificador = $this->clientCode($orderId).$this->digitVerifier($this->clientCode($orderId));

        $fecha = \DateTime::createFromFormat('Y-m-d', $dueYmd);

        $body = [
            'cliente' => $this->bersacode(),
            'mes' => (int) $fecha->format('m'),
            'anio' => (int) $fecha->format('Y'),
            'base' => [[
                'cod_cliente' => $identificador,
                'nombre_cliente' => 'Compra de entradas - Orden '.$orderId,
                'otros' => 'entradas-'.$orderId,
                'fecha_primer_vencimiento' => $dueYmd,
                'importe_primer_vencimiento' => round($importe, 2),
                'fecha_segundo_vencimiento' => '',
                'importe_segundo_vencimiento' => '',
                'concepto' => 'Entradas',
            ]],
        ];

        $response = Http::withBasicAuth($this->methodConfig()['username'] ?? '', $this->methodConfig()['password'] ?? '')
            ->withHeaders(['Content-Type' => 'application/json'])
            ->timeout(60)
            ->post($url, $body);

        $json = $response->json();

        return [
            'status' => (string) ($json['status'] ?? ($response->failed() ? 'ERROR' : 'UNKNOWN')),
            'message' => is_string($json['message'] ?? null) ? $json['message'] : $response->body(),
        ];
    }
}
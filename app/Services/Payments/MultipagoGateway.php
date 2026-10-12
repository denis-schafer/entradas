<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Integracion con Multipago Online.
 *
 * Tres piezas (ver referencia en C:\Users\Denis\Downloads\Multipago):
 *   - PUM (boton de pago): codigo de barras de 28 digitos que abre el pago.
 *     Composicion: comercio(4) + cliente(6, ultimos `large` digitos) +
 *     vencimiento ddmmaa(6) + importe sin coma(11) + DV modulo 10 (1).
 *   - QR nativo: identificador = comercio(4) + cliente(6) (igual que el boton); el
 *     contenido del QR es cabecera + sector1(81 + largo + dato) + sector2 +
 *     CRC16-CCITT. El `dato` es un TLV: 00=identificador Multipago,
 *     01=importe, 02=hash corto SHA-256(seeder+identificador), 03=vencimiento
 *     ddmmaa, 04=identificador del pago (nuestro order_id). Requiere `header`
 *     y `seeder` del convenio. No se presenta deuda: Multipago lee importe y
 *     vencimiento del propio QR.
 *   - Webhook en tiempo real: POST/GET con el JSON {customer_id, amount,
 *     identifier, fecha_pago} (y opcionalmente id_pago con nuestro order_id).
 *     Es UNA sola URL para todos los eventos (una cuenta de Multipago); se
 *     identifica por la key de la URL y se deduplica por identifier. El evento
 *     surge de la orden resuelta por id_pago o, si falta, por customer_id.
 *   - Consulta masiva: GET consultar_deuda/{bersacode}/{desde}/{hasta} con
 *     Basic Auth; se usa para "Validar pagos" desde el modulo.
 *
 * El "cliente" de Multipago es nuestro order_id (identificador del pago),
 * convertido a 6 digitos tomando los ultimos `large` digitos del order_id.
 *
 * Credenciales: como en MercadoPago, la cuenta puede ser global (config del
 * medio) o propia de un evento (config del pivote). Un campo cargado en el
 * evento gana al global; en blanco, se opera con el global. El webhook sigue
 * siendo UNICO/global (una sola URL y key para toda la instalacion).
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
        return ['bersacode', 'username', 'password', 'differentiator', 'webhook_key', 'header', 'seeder'];
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
     * Credenciales efectivas de un evento: el valor del evento si esta cargado,
     * si no el global. Con $eventId null opera la cuenta global.
     */
    public function bersacodeForEvent(?int $eventId = null): string
    {
        return trim((string) ($eventId === null
            ? $this->bersacode()
            : $this->resolveConfig($eventId, 'bersacode', '')));
    }

    public function usernameForEvent(?int $eventId = null): string
    {
        return trim((string) ($eventId === null
            ? ($this->methodConfig()['username'] ?? '')
            : $this->resolveConfig($eventId, 'username', '')));
    }

    public function passwordForEvent(?int $eventId = null): string
    {
        return trim((string) ($eventId === null
            ? ($this->methodConfig()['password'] ?? '')
            : $this->resolveConfig($eventId, 'password', '')));
    }

    /**
     * Encabezado del convenio para el QR nativo (lo entrega Multipago). Trae al
     * final el sector 2 (13 caracteres) y un CRC de ejemplo (4) que se descarta.
     */
    public function headerForEvent(?int $eventId = null): string
    {
        return trim((string) ($eventId === null
            ? ($this->methodConfig()['header'] ?? '')
            : $this->resolveConfig($eventId, 'header', '')));
    }

    /**
     * Semilla del hash corto del QR nativo (la entrega Multipago).
     */
    public function seederForEvent(?int $eventId = null): string
    {
        return trim((string) ($eventId === null
            ? ($this->methodConfig()['seeder'] ?? '')
            : $this->resolveConfig($eventId, 'seeder', '')));
    }

    /**
     * El QR nativo puede generarse para un evento (header + seeder cargados).
     */
    public function qrReady(?int $eventId = null): bool
    {
        return $this->headerForEvent($eventId) !== '' && $this->seederForEvent($eventId) !== '';
    }

    /**
     * Cantidad de digitos del codigo de cliente para un evento (override del
     * evento si esta cargado, si no el global).
     */
    public function largeForEvent(?int $eventId = null): int
    {
        if ($eventId === null) {
            return $this->large();
        }

        return max(1, min((int) $this->resolveConfig($eventId, 'large', $this->large()), 10));
    }

    /**
     * Modulo QR habilitado PARA ESE EVENTO (se configura por evento; default:
     * habilitado si nunca se configuro).
     */
    public function qrEnabled(int $eventId): bool
    {
        return $this->toBool($this->resolveConfig($eventId, 'qr_enabled', true), true);
    }

    /**
     * Modulo Boton de pago (codigo de barras PUM) habilitado para ese evento.
     */
    public function buttonEnabled(int $eventId): bool
    {
        return $this->toBool($this->resolveConfig($eventId, 'button_enabled', true), true);
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * Codigo de cliente de Multipago: 6 digitos con el order_id (los ultimos
     * `large` digitos significativos), completado con ceros a la izquierda.
     */
    public function clientCode(int $orderId): string
    {
        return $this->clientCodeForEvent($orderId, null);
    }

    /**
     * Codigo de cliente con el `large` efectivo de un evento.
     */
    public function clientCodeForEvent(int $orderId, ?int $eventId = null): string
    {
        $digits = (string) $orderId;
        $large = $this->largeForEvent($eventId);

        return str_pad(substr($digits, -$large), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Identificador del cliente ante Multipago (el que va dentro del QR):
     * comercio(4) + codigo de cliente(6), igual que en el boton de pago.
     */
    public function customerIdentifier(int $orderId, ?int $eventId = null): string
    {
        $bersacode = str_pad($this->bersacodeForEvent($eventId), 4, '0', STR_PAD_LEFT);

        return $bersacode.$this->clientCodeForEvent($orderId, $eventId);
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
     * @param  int|null  $eventId  evento de la orden: usa su cuenta si tiene propia
     */
    public function pumCode(int $orderId, string $dueYmd, float $importe, ?int $eventId = null): string
    {
        $bersacode = str_pad($this->bersacodeForEvent($eventId), 4, '0', STR_PAD_LEFT);
        $cliente = $this->clientCodeForEvent($orderId, $eventId);
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
     * Hash corto del QR: SHA-256(seeder + identificador), primeros 2 bytes en
     * hexadecimal de 4 caracteres.
     */
    public function shortHash(string $identificador, string $seeder): string
    {
        $bytes = hash('sha256', $seeder.$identificador, true);
        $corto = ((ord($bytes[0]) & 0xFF) << 8) | (ord($bytes[1]) & 0xFF);

        return strtoupper(str_pad(dechex($corto), 4, '0', STR_PAD_LEFT));
    }

    /**
     * CRC16-CCITT (inicial 0xFFFF, polinomio 0x1021) en hexadecimal de 4.
     */
    public function crc16(string $datos): string
    {
        $crc = 0xFFFF;

        foreach (str_split($datos) as $caracter) {
            $b = ord($caracter);

            for ($i = 0; $i < 8; $i++) {
                $bit = (($b >> (7 - $i)) & 1) === 1;
                $c15 = (($crc >> 15) & 1) === 1;
                $crc <<= 1;

                if ($c15 xor $bit) {
                    $crc ^= 0x1021;
                }
            }

            $crc &= 0xFFFF;
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Contenido del QR nativo de Multipago para cobrar una orden.
     *
     * Trama (todo ASCII): cabecera + sector1 + sector2 + CRC16.
     *   sector1 = '81' + largo(dato) + dato
     *   dato    = TLV con largo de 2 digitos en cada campo:
     *             00 identificador Multipago (comercio4+cliente6)
     *             01 importe (2 decimales)
     *             02 hash corto SHA-256(seeder+identificador)
     *             03 vencimiento ddmmaa
     *             04 identificador del pago (order_id)
     *   cabecera/sector2 se recortan del `header` del convenio (descarta su CRC).
     *
     * No presenta deuda: Multipago lee importe y vencimiento del propio QR.
     *
     * @param  string  $dueYmd  fecha de vencimiento Y-m-d
     *
     * @throws PaymentSetupException  si el evento no tiene header/seeder
     */
    public function qrContent(int $orderId, string $dueYmd, float $importe, ?int $eventId = null): string
    {
        $header = $this->headerForEvent($eventId);

        if ($header === '' || $this->seederForEvent($eventId) === '') {
            throw new PaymentSetupException('Multipago QR: falta el encabezado o la semilla del convenio.', 422);
        }

        $identificador = $this->customerIdentifier($orderId, $eventId);
        $hash = $this->shortHash($identificador, $this->seederForEvent($eventId));
        $vencimiento = \DateTime::createFromFormat('Y-m-d', $dueYmd)->format('dmy');
        $importe = number_format($importe, 2, '.', '');
        $pago = (string) $orderId;

        $dato = '00'.$this->len2(strlen($identificador)).$identificador
            .'01'.$this->len2(strlen($importe)).$importe
            .'02'.$this->len2(strlen($hash)).$hash
            .'03'.$this->len2(strlen($vencimiento)).$vencimiento
            .'04'.$this->len2(strlen($pago)).$pago;

        $sector1 = '81'.$this->len2(strlen($dato)).$dato;
        $cabecera = substr($header, 0, -17);
        $sector2 = substr($header, -17, -4);
        $sinCrc = $cabecera.$sector1.$sector2;

        return $sinCrc.$this->crc16($sinCrc);
    }

    /**
     * Largo de campo en 2 digitos (TLV del QR nativo).
     */
    private function len2(int $length): string
    {
        return str_pad((string) $length, 2, '0', STR_PAD_LEFT);
    }

    /**
     * A partir del customer_id que reporta Multipago (comercio4 + cliente6),
     * resuelve nuestro order_id tomando los ultimos 6 digitos (el codigo de
     * cliente), que es el order_id completado con ceros.
     */
    public function resolveOrderId(string $customerId): ?int
    {
        $customerId = trim($customerId);

        if ($customerId === '' || ! ctype_digit($customerId)) {
            return null;
        }

        $codigo = substr($customerId, -6);
        $orderId = (int) $codigo;

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
     * Order_id explicito que Multipago puede mandar en el webhook (porque va
     * dentro del QR). Acepta unos cuantos nombres por si el convenio cambia.
     */
    private function explicitOrderId(array $cobro): ?int
    {
        foreach (['id_pago', 'order_id', 'identificador_pago', 'payment_id'] as $key) {
            $value = $cobro[$key] ?? null;

            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Normaliza y valida un cobro del webhook.
     *
     * @return array{ok: bool, message: string, order_id: ?int, payment_id: ?string, amount: ?float}
     */
    public function normalizeWebhook(array $cobro): array
    {
        $orderId = $this->explicitOrderId($cobro);

        if ($orderId === null) {
            $orderId = $this->resolveOrderId((string) ($cobro['customer_id'] ?? ''));
        }

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
     * @param  int|null  $eventId  null = cuenta global; si no, la cuenta del evento
     * @return array{status: string, cobros: array}
     */
    public function consultCobros(?string $desde = null, ?string $hasta = null, ?int $eventId = null): array
    {
        $desde = $desde ?: now()->subDays((int) ($this->methodConfig()['days'] ?? 7))->toDateString();
        $hasta = $hasta ?: now()->toDateString();

        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $url = self::API_BASE.'consultar_deuda/'.$this->bersacodeForEvent($eventId).'/'.$desde.'/'.$hasta;

        $response = Http::withBasicAuth($this->usernameForEvent($eventId), $this->passwordForEvent($eventId))
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
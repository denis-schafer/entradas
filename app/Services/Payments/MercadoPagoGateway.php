<?php

namespace App\Services\Payments;

use App\Support\MercadoPagoToken;
use Illuminate\Support\Facades\DB;

/**
 * Credenciales de MercadoPago.
 *
 * La resolucion del access_token (evento -> plataforma) queda en
 * App\Support\MercadoPagoToken para que el flujo de cobro no pueda divergir
 * del modulo: este gateway delega ahi. Aca se suman las credenciales de OAuth
 * (client_id / client_secret ahora configurables en el modulo) y, a futuro, la
 * construccion de la preference si se quiere sacar del controlador.
 */
class MercadoPagoGateway extends PaymentGateway
{
    public function code(): string
    {
        return 'mercadopago';
    }

    public function name(): string
    {
        return 'MercadoPago';
    }

    public function secretKeys(): array
    {
        return ['client_id', 'client_secret', 'platform_access_token', 'access_token'];
    }

    public function clientId(): ?string
    {
        $fromModule = trim((string) ($this->methodConfig()['client_id'] ?? ''));

        return $fromModule !== ''
            ? $fromModule
            : (config('services.mercadopago.client_id') ?? env('MP_CLIENT_ID'));
    }

    public function clientSecret(): ?string
    {
        $fromModule = trim((string) ($this->methodConfig()['client_secret'] ?? ''));

        return $fromModule !== ''
            ? $fromModule
            : (config('services.mercadopago.client_secret') ?? env('MP_CLIENT_SECRET'));
    }

    public function platformToken(): ?string
    {
        return MercadoPagoToken::platform();
    }

    public function eventToken(int $eventId): ?string
    {
        return MercadoPagoToken::forEvent(DB::table('tickets_events')->where('id', $eventId)->first());
    }

    /**
     * Token propio del evento (OAuth), SIN caer a la plataforma.
     */
    public function ownEventToken(int $eventId): ?string
    {
        return MercadoPagoToken::ownEventToken($eventId);
    }

    public function storeEventToken(int $eventId, ?string $token): void
    {
        MercadoPagoToken::storeForEvent($eventId, $token);
    }
}
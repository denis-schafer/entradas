<?php

namespace App\Services\Payments;

/**
 * Fabrica de gateways por code. Los codes que existen aca son los unicos que la
 * app entiende; un code suelto en la tabla es config corrupta y se ignora.
 */
class PaymentGatewayRegistry
{
    public static function for(string $code): ?PaymentGateway
    {
        return match ($code) {
            'mercadopago' => new MercadoPagoGateway,
            'multipago' => new MultipagoGateway,
            default => null,
        };
    }

    /**
     * @return PaymentGateway[]
     */
    public static function all(): array
    {
        return [
            new MercadoPagoGateway,
            new MultipagoGateway,
        ];
    }
}
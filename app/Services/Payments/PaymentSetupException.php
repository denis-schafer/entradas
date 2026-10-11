<?php

namespace App\Services\Payments;

/**
 * Falla esperable al preparar un cobro (falta de credenciales, evento sin
 * medio habilitado, MercadoPago rechazo la preferencia...).
 *
 * El mensaje es apto para mostrarle al operador y el status es el HTTP que le
 * corresponde a esa respuesta.
 */
class PaymentSetupException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 400)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}

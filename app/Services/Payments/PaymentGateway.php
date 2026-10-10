<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;

/**
 * Contrato de un medio de pago. Cada gateway conoce su credencial y como se
 * guarda la config global (tickets_payment_methods.config) y la config por
 * evento (tickets_event_payment_method.config).
 *
 * Reglas de resolucion:
 *   - Un valor puesto en el evento gana al valor global del metodo.
 *   - `enabled` del metodo apaga el medio en todos los eventos.
 *   - Sin pivot de evento, el medio hereda la config global (y queda
 *     habilitado si el metodo global lo esta).
 *
 * No se expone ninguna credencial en claro nunca: el controlador del modulo
 * manda la config por maskConfig() antes de devolverla al frontend.
 */
abstract class PaymentGateway
{
    abstract public function code(): string;

    abstract public function name(): string;

    /**
     * Keys de config que nunca se devuelven en claro (mascara para la UI).
     */
    abstract public function secretKeys(): array;

    public function methodRow(): ?object
    {
        return DB::table('tickets_payment_methods')
            ->where('code', $this->code())
            ->first(['id', 'enabled', 'config']);
    }

    public function methodId(): ?int
    {
        $row = $this->methodRow();

        return $row ? (int) $row->id : null;
    }

    public function methodConfig(): array
    {
        $row = $this->methodRow();

        return $row ? (json_decode((string) ($row->config ?? '[]'), true) ?: []) : [];
    }

    public function globallyEnabled(): bool
    {
        $row = $this->methodRow();

        return $row !== null && (int) $row->enabled === 1;
    }

    public function eventPivot(int $eventId): ?object
    {
        $methodId = $this->methodId();

        if (! $methodId) {
            return null;
        }

        return DB::table('tickets_event_payment_method')
            ->where('event_id', $eventId)
            ->where('payment_method_id', $methodId)
            ->first(['enabled', 'config']);
    }

    public function eventConfig(int $eventId): array
    {
        $pivot = $this->eventPivot($eventId);

        return $pivot ? (json_decode((string) ($pivot->config ?? '[]'), true) ?: []) : [];
    }

    public function eventEnabled(int $eventId): bool
    {
        if (! $this->globallyEnabled()) {
            return false;
        }

        $pivot = $this->eventPivot($eventId);

        if (! $pivot) {
            return true;
        }

        return (int) $pivot->enabled === 1;
    }

    /**
     * Valor de config para un evento: el override del evento si existe, sino el
     * global del metodo.
     */
    public function resolveConfig(int $eventId, string $key, mixed $default = null): mixed
    {
        $eventConfig = $this->eventConfig($eventId);

        if (array_key_exists($key, $eventConfig) && $eventConfig[$key] !== '' && $eventConfig[$key] !== null) {
            return $eventConfig[$key];
        }

        $globalConfig = $this->methodConfig();

        return $globalConfig[$key] ?? $default;
    }

    /**
     * Config segura para mostrar en la UI: las keys secretas van enmascaradas.
     */
    public function maskConfig(array $config): array
    {
        $secrets = $this->secretKeys();
        $masked = [];

        foreach ($config as $key => $value) {
            $masked[$key] = in_array($key, $secrets, true) && $value !== ''
                ? '••••••••'
                : $value;
        }

        return $masked;
    }
}
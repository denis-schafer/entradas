/*
|--------------------------------------------------------------------------
| Medios de pago (frontend)
|--------------------------------------------------------------------------
|
| Opciones para filtrar listados por medio de pago y una etiqueta legible.
| El valor viaja al backend tal cual: 'efectivo', 'mercadopago', 'multipago',
| 'multipago:qr' o 'multipago:button'. Las ordenes viejas de Multipago sin
| modalidad se tratan como QR (el backend incluye el NULL en 'multipago:qr').
*/

export const PAYMENT_FILTERS = [
    { value: '', label: 'Todos los medios' },
    { value: 'efectivo', label: 'Efectivo' },
    { value: 'mercadopago', label: 'MercadoPago' },
    { value: 'multipago:qr', label: 'Multipago (QR)' },
    { value: 'multipago:button', label: 'Multipago (Boton de pago)' },
];

export function paymentMethodLabel(method, variant = null) {
    if (method === 'multipago') {
        return variant === 'button' ? 'Multipago (Boton de pago)' : 'Multipago (QR)';
    }

    const labels = {
        efectivo: 'Efectivo',
        mercadopago: 'MercadoPago',
    };

    return labels[method] || '—';
}

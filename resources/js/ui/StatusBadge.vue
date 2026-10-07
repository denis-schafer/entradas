<script setup>
/**
 * Estado de una orden o de un boleto.
 *
 * El mapa vive aca y no duplicado por pantalla: asi "pending" no sale violeta en
 * una tabla y gris en otra. Es una decision de presentacion, no de negocio, asi
 * que el server manda el estado crudo y este componente decide el color.
 */
const TONES = {
    paid: 'success',
    approved: 'success',
    valid: 'success',
    published: 'success',
    used: 'muted',
    pending: 'warning',
    in_progress: 'warning',
    draft: 'muted',
    closed: 'muted',
    expired: 'muted',
    cancelled: 'danger',
    rejected: 'danger',
    failed: 'danger',
};

const LABELS = {
    paid: 'Pagada',
    approved: 'Aprobado',
    valid: 'Valida',
    published: 'Publicado',
    used: 'Usada',
    pending: 'Pendiente',
    draft: 'Borrador',
    closed: 'Cerrado',
    expired: 'Vencida',
    cancelled: 'Cancelada',
    rejected: 'Rechazado',
    failed: 'Fallida',
};

defineProps({
    status: { type: String, required: true },
    label: { type: String, default: null },
});

function toneOf(status) {
    return TONES[status] || 'muted';
}

function labelOf(status) {
    return LABELS[status] || status;
}
</script>

<template>
    <span class="et-badge" :class="`et-badge--${toneOf(status)}`">
        {{ label || labelOf(status) }}
    </span>
</template>
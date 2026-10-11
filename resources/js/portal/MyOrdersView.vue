<script setup>
/**
 * Historial de compras del comprador.
 *
 * Una orden pendiente se puede cancelar desde aca, que es lo que libera el
 * stock reservado. El backend no deja cancelar una orden pagada ni una que ya
 * tenga entradas usadas, asi que el boton se oculta en vez de dejar fallar.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { listen, startPolling, stopPolling } from '../realtime.js';
import StatusBadge from '../ui/StatusBadge.vue';
import EmptyState from '../ui/EmptyState.vue';
import { toast } from '../ui/toast.js';

defineProps({
    user: { type: Object, default: null },
    config: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['navigate']);

const orders = ref([]);
const loading = ref(true);
const error = ref('');
const cancelling = ref(null);
const confirming = ref(null);
const paidModal = ref(null);

// Estado anterior por orden: cuando una pasa de 'pending' a 'paid' avisamos con
// un modal. Las suscripciones a los canales privados de cada orden pendiente
// hacen que ese cambio llegue apenas el webhook confirma el pago.
const statusById = new Map();
const subs = new Map();

async function load() {
    try {
        const { data } = await api.get('tickets-portal/api/my-orders');

        syncRealtime(data || []);

        orders.value = data;
        error.value = '';
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

function syncRealtime(list) {
    list.forEach((order) => {
        const prev = statusById.get(order.id);

        if (prev === 'pending' && order.status === 'paid') {
            paidModal.value = order;
        }

        statusById.set(order.id, order.status);

        if (order.status === 'pending' && order.public_token && !subs.has(order.public_token)) {
            subs.set(
                order.public_token,
                listen(`tickets.order.${order.public_token}`, 'order.status', load)
            );
        }
    });

    const stillPending = new Set(
        list
            .filter((order) => order.status === 'pending' && order.public_token)
            .map((order) => order.public_token)
    );

    [...subs.keys()].forEach((token) => {
        if (!stillPending.has(token)) {
            subs.get(token)?.();
            subs.delete(token);
        }
    });
}

function goToTickets() {
    paidModal.value = null;
    emit('navigate', 'my-tickets');
}

function money(value) {
    return new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        minimumFractionDigits: 0,
    }).format(Number(value || 0));
}

function formatDate(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleDateString('es-AR', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatTime(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
}

function paymentLabel(order) {
    if (order.payment_mode === 'installments') {
        return `${order.installment_count} cuotas`;
    }

    return 'Pago unico';
}

function canCancel(order) {
    return order.status === 'pending';
}

function requestCancel(order) {
    confirming.value = order.id;
}

function doCancel(order) {
    cancelling.value = order.id;

    api.post(`tickets-portal/api/orders/${order.id}/cancel`)
        .then(() => {
            toast('Orden cancelada. El stock quedo disponible.', 'success');

            return load();
        })
        .catch((err) => {
            toast(toError(err).message, 'danger');
        })
        .finally(() => {
            cancelling.value = null;
            confirming.value = null;
        });
}

onMounted(async () => {
    await load();

    // Mientras haya ordenes pendientes, el polling mantiene el historial al dia
    // sin depender de que el canal privado este disponible.
    startPolling(load);
});

onBeforeUnmount(() => {
    stopPolling();
    subs.forEach((off) => off?.());
    subs.clear();
});
</script>

<template>
    <div class="orders">
        <header class="mb-4">
            <h1 class="h3 fw-bold mb-1">Mis compras</h1>
            <p class="text-muted-2 mb-0">Ordenes pagadas, pendientes y canceladas.</p>
        </header>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <EmptyState
            v-else-if="!orders.length"
            icon="bi-receipt"
            title="Todavia no compraste nada"
            hint="Tu historial de compras aparece aca."
        >
            <button class="btn btn-et-primary btn-sm" @click="emit('navigate', 'events')">Ver eventos</button>
        </EmptyState>

        <div v-else class="d-flex flex-column gap-2">
            <article v-for="order in orders" :key="order.id" class="order et-surface">
                <div class="order__main">
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                        <h2 class="h6 fw-bold mb-0">{{ order.event_name || 'Evento' }}</h2>
                        <StatusBadge :status="order.status" />
                    </div>

                    <p class="text-muted-2 small mb-0 numeric">
                        <span class="fw-bold">{{ money(order.total) }}</span>
                        · {{ order.ticket_count }} {{ order.ticket_count === 1 ? 'entrada' : 'entradas' }}
                        · {{ paymentLabel(order) }}
                        · {{ formatDate(order.created_at) }}
                        <span v-if="formatTime(order.created_at)">{{ formatTime(order.created_at) }}</span>
                    </p>
                </div>

                <div class="order__actions">
                    <button
                        v-if="order.status === 'paid'"
                        class="btn btn-et-primary btn-sm"
                        @click="emit('navigate', 'my-tickets')"
                    >
                        Ver entradas
                    </button>

                    <button
                        v-else-if="order.status === 'pending'"
                        class="btn btn-et-primary btn-sm"
                        @click="emit('navigate', 'payment', { id: order.id })"
                    >
                        Pagar
                    </button>

                    <button
                        v-if="order.status === 'pending'"
                        class="btn btn-et-ghost btn-sm"
                        @click="emit('navigate', 'order-result', { order_id: order.id })"
                    >
                        Ver estado
                    </button>

                    <template v-if="canCancel(order)">
                        <button
                            v-if="confirming !== order.id"
                            class="btn btn-et-ghost btn-sm"
                            @click="requestCancel(order)"
                        >
                            Cancelar
                        </button>

                        <template v-else>
                            <button
                                class="btn btn-et-ghost btn-sm"
                                :disabled="cancelling === order.id"
                                @click="doCancel(order)"
                            >
                                <span v-if="cancelling === order.id" class="spinner-border spinner-border-sm"></span>
                                <template v-else>Confirmar</template>
                            </button>

                            <button class="btn btn-link btn-sm" @click="confirming = null">
                                No
                            </button>
                        </template>
                    </template>
                </div>
            </article>
        </div>

        <div v-if="paidModal" class="paid-modal no-print" @click.self="paidModal = null">
            <div class="paid-modal__card et-surface-raised">
                <div class="paid-modal__icon">
                    <i class="bi bi-check-circle-fill"></i>
                </div>

                <h2 class="h5 fw-bold mb-2">¡Pago confirmado!</h2>
                <p class="text-muted-2 mb-4">
                    Tu compra de {{ paidModal.event_name || 'el evento' }} se acreditó.
                    Ya podés ver y descargar tus entradas.
                </p>

                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-et-primary btn-sm" @click="goToTickets">
                        Ver mis entradas
                    </button>
                    <button class="btn btn-et-ghost btn-sm" @click="paidModal = null">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.orders {
    min-height: 40vh;
}

.order {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.9rem 1.1rem;
    flex-wrap: wrap;
}

.order__main {
    min-width: 0;
}

.order__actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-shrink: 0;
}

.paid-modal {
    position: fixed;
    inset: 0;
    z-index: 1080;
    display: grid;
    place-items: center;
    padding: 1rem;
    background: rgb(0 0 0 / 55%);
    backdrop-filter: blur(2px);
}

.paid-modal__card {
    width: min(420px, 100%);
    padding: 2rem 1.75rem;
    border-radius: var(--et-radius-lg);
    text-align: center;
    animation: paid-pop 180ms ease-out;
}

.paid-modal__icon {
    width: 68px;
    height: 68px;
    margin: 0 auto 1rem;
    border-radius: 50%;
    display: grid;
    place-items: center;
    font-size: 2rem;
    background: rgb(46 213 115 / 14%);
    color: var(--et-success);
}

@keyframes paid-pop {
    from {
        opacity: 0;
        transform: translateY(12px) scale(0.97);
    }

    to {
        opacity: 1;
        transform: none;
    }
}
</style>
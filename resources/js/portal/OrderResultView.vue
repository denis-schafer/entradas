<script setup>
/**
 * Resultado del pago: la pantalla a la que vuelve el comprador desde MercadoPago.
 *
 * La URL de retorno esta pelada (ver TicketsMercadoPagoController::callback),
 * asi que esta vista no recibe un token: resuelve la orden desde sessionStorage
 * o, si no, desde la ultima orden abierta del comprador.
 *
 * Mientras el estado es 'pending' NO se dice ni "listo" ni "fallo". MercadoPago
 * aprueba en el navegador y confirma en el webhook, y hay una ventana de
 * segundos entre una cosa y la otra. Decir "pago rechazado" durante esa ventana
 * seria mentira; por eso dice "confirmando" y sigue preguntando.
 *
 * La subscription al canal privado de la orden es la que hace que el mensaje
 * cambie solo cuando el webhook arrive, y el polling esta siempre abajo como
 * respaldo.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { listen, startPolling, stopPolling } from '../realtime.js';
import { forgetOrder, readPendingOrder } from '../shell/router.js';
import StatusBadge from '../ui/StatusBadge.vue';
import EmptyState from '../ui/EmptyState.vue';

const props = defineProps({
    order_id: { type: [Number, String], default: null },
    token: { type: String, default: null },
    user: { type: Object, default: null },
    config: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['navigate']);

const order = ref(null);
const orderId = ref(null);
const loading = ref(true);
const error = ref('');

let unsubscribe = null;

async function locate() {
    if (props.order_id) {
        return Number(props.order_id);
    }

    const stash = readPendingOrder();

    if (stash?.order_id) {
        return Number(stash.order_id);
    }

    const { data } = await api.get('tickets-portal/api/my-orders');

    const open = (data || []).find((o) => o.status === 'pending' || o.status === 'paid');

    return open ? open.id : null;
}

async function load() {
    try {
        if (!orderId.value) {
            orderId.value = await locate();
        }

        if (!orderId.value) {
            loading.value = false;

            return;
        }

        const { data } = await api.get(`tickets-portal/api/orders/${orderId.value}/status`);

        order.value = data;
        error.value = '';

        // Cuando la orden se resuelve, deja de hacer falta la guia de salto.
        if (data.status === 'paid') {
            forgetOrder(orderId.value);
        }
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

const status = computed(() => order.value?.status || 'pending');

const isPending = computed(() => status.value === 'pending');

const VIEW = {
    pending: {
        icon: 'bi-hourglass-split',
        tone: 'warning',
        title: 'Confirmando tu pago',
        body: 'MercadoPago esta confirmando el pago. Esto suele tardar unos segundos.',
    },
    paid: {
        icon: 'bi-check-circle-fill',
        tone: 'success',
        title: 'Listo, tus entradas estan listas',
        body: 'Ya podés verlas y descargarlas desde la seccion de entradas.',
    },
    rejected: {
        icon: 'bi-x-circle-fill',
        tone: 'danger',
        title: 'No pudimos confirmar el pago',
        body: 'MercadoPago no aprobo la operacion. Podes intentar de nuevo.',
    },
    cancelled: {
        icon: 'bi-slash-circle',
        tone: 'danger',
        title: 'La orden fue cancelada',
        body: 'Se libero el stock reservado. Podes comprar de nuevo si quedo disponibilidad.',
    },
    expired: {
        icon: 'bi-clock-history',
        tone: 'danger',
        title: 'La orden vencio',
        body: ' paso el tiempo de pago y el stock quedo disponible otra vez.',
    },
};

const view = computed(() => VIEW[status.value] || VIEW.pending);

function money(value) {
    return new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        minimumFractionDigits: 0,
    }).format(Number(value || 0));
}

async function retry() {
    emit('navigate', 'events');
}

onMounted(async () => {
    await load();

    // El canal privado de la orden es lo que dispara el refresco. El token
    // opaco viaja en el canal y no en la URL, y el backend verifica que la
    // orden sea de esta sesion antes de autorizar la suscripcion.
    if (order.value?.public_token) {
        unsubscribe = listen(
            `tickets.order.${order.value.public_token}`,
            'order.status',
            load
        );
    }

    startPolling(load);
});

onBeforeUnmount(() => {
    unsubscribe?.();
    stopPolling();
});
</script>

<template>
    <div class="result">
        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <EmptyState
            v-else-if="!order"
            icon="bi-receipt"
            title="No encontramos una orden para mostrar"
            :hint="error"
        >
            <button class="btn btn-et-primary btn-sm" @click="retry">Ver eventos</button>
        </EmptyState>

        <div v-else class="result__card et-surface-raised">
            <div class="result__icon" :class="`result__icon--${view.tone}`">
                <i class="bi" :class="view.icon"></i>
            </div>

            <h1 class="h4 fw-bold mb-2">{{ view.title }}</h1>
            <p class="text-muted-2 mb-3">{{ view.body }}</p>

            <div class="d-flex justify-content-center mb-4">
                <StatusBadge :status="status" />
            </div>

            <div class="result__meta text-start">
                <div class="d-flex justify-content-between small mb-2">
                    <span class="text-faint">Orden</span>
                    <span class="numeric">#{{ order.id }}</span>
                </div>

                <div v-if="order.paid_at" class="d-flex justify-content-between small mb-2">
                    <span class="text-faint">Pagada el</span>
                    <span class="numeric">
                        {{ new Date(order.paid_at).toLocaleString('es-AR') }}
                    </span>
                </div>

                <div v-if="isPending" class="d-flex justify-content-between small">
                    <span class="text-faint">Referencia</span>
                    <span class="numeric">{{ order.public_token?.slice(0, 8) }}</span>
                </div>
            </div>

            <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center mt-4 no-print">
                <button
                    v-if="status === 'paid'"
                    class="btn btn-et-primary"
                    @click="emit('navigate', 'my-tickets')"
                >
                    <i class="bi bi-qr-code me-1"></i>Ver mis entradas
                </button>

                <button
                    v-if="status === 'paid'"
                    class="btn btn-et-ghost"
                    @click="emit('navigate', 'events')"
                >
                    Ver otros eventos
                </button>

                <button
                    v-if="['rejected', 'cancelled', 'expired'].includes(status)"
                    class="btn btn-et-primary"
                    @click="emit('navigate', 'events')"
                >
                    Intentar de nuevo
                </button>

                <button
                    v-if="isPending"
                    class="btn btn-et-ghost"
                    @click="emit('navigate', 'events')"
                >
                    Volver a los eventos
                </button>
            </div>

            <p v-if="isPending" class="text-faint small text-center mt-3 mb-0 no-print">
                <span class="spinner-border spinner-border-sm me-2"></span>
                Actualizando solo, no hace falta que recargues.
            </p>
        </div>
    </div>
</template>

<style scoped>
.result {
    min-height: 58vh;
    display: grid;
    place-items: center;
}

.result__card {
    width: min(520px, 100%);
    padding: 2.5rem 2rem;
    border-radius: var(--et-radius-lg);
    text-align: center;
}

.result__icon {
    width: 72px;
    height: 72px;
    margin: 0 auto 1.25rem;
    border-radius: 50%;
    display: grid;
    place-items: center;
    font-size: 2rem;
}

.result__icon--success {
    background: rgb(46 213 115 / 14%);
    color: var(--et-success);
}

.result__icon--warning {
    background: rgb(255 193 7 / 14%);
    color: var(--et-warning);
}

.result__icon--danger {
    background: rgb(255 61 113 / 14%);
    color: var(--et-danger);
}

.result__meta {
    border-top: 1px solid var(--et-border);
    padding-top: 1rem;
}
</style>
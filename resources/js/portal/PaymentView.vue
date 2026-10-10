<script setup>
/**
 * Eleccion del medio de pago + screens de cada medio.
 *
 * Llega desde EventDetailView con el order_id recien creado. Pide la lista de
 * medios habilitados para el evento y deja elegir:
 *   - MercadoPago: crea la preference y salta al checkout de MP (flujo de
 *     siempre).
 *   - Multipago: muestra el QR del codigo PUM y espera el pago (webhook o
 *     validacion). Al confirmarse, va a OrderResult.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { toast } from '../ui/toast.js';

const props = defineProps({
    id: { type: [Number, String], default: null },
    user: { type: Object, default: null },
    config: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['navigate']);

const orderId = Number(props.id);

const loading = ref(true);
const error = ref('');
const methods = ref([]);
const chosen = ref(null);
const paying = ref(false);

const qr = ref(null);
const paid = ref(false);
const cancelled = ref(false);

let pollTimer = null;

async function loadMethods() {
    loading.value = true;

    try {
        const { data } = await api.get(`tickets-portal/api/orders/${orderId}/payment-methods`);

        methods.value = data.methods || [];
        error.value = '';

        if (methods.value.length === 1) {
            choose(methods.value[0]);
        }
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

async function choose(method) {
    chosen.value = method;

    if (method.type === 'redirect') {
        await startMercadoPago();
    } else {
        await startMultipago();
    }
}

async function startMercadoPago() {
    paying.value = true;
    error.value = '';

    try {
        const { data } = await api.post(
            `tickets-portal/api/orders/${orderId}/preference`
        );

        const target = data.init_point || data.sandbox_init_point;

        if (!target) {
            error.value = 'El medio no devolvio una URL de pago. Intenta de nuevo.';

            return;
        }

        window.location.href = target;
    } catch (err) {
        error.value = toError(err).message;
        toast(error.value, 'danger');
    } finally {
        paying.value = false;
    }
}

async function startMultipago() {
    try {
        const { data } = await api.get(
            `tickets-portal/api/orders/${orderId}/multipago-code`
        );

        qr.value = data;
        startPolling();
    } catch (err) {
        error.value = toError(err).message;
        toast(error.value, 'danger');
    }
}

function startPolling() {
    stopPolling();

    pollTimer = setInterval(async () => {
        try {
            const { data } = await api.get(`tickets-portal/api/orders/${orderId}/status`);

            if (data.status === 'paid') {
                paid.value = true;
                stopPolling();
                emit('navigate', 'order-result', { order_id: orderId });
            } else if (data.status === 'cancelled' || data.status === 'expired') {
                cancelled.value = true;
                stopPolling();
            }
        } catch {
            // Se sigue reintentando; el poll es solo de respaldo.
        }
    }, 3000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function goBackToList() {
    chosen.value = null;
    qr.value = null;
    cancelled.value = false;
}

function onNavigate(name, params) {
    emit('navigate', name, params);
}

function formatAmount(value) {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(value);
}

onMounted(loadMethods);
onBeforeUnmount(stopPolling);
</script>

<template>
    <div class="payment">
        <h2 class="payment__title">Completar el pago</h2>

        <div v-if="loading" class="d-flex justify-content-center py-5">
            <div class="spinner-border text-primary"></div>
        </div>

        <div v-else-if="error" class="alert alert-danger small">{{ error }}</div>

        <template v-else-if="!chosen">
            <p class="text-muted-2 small">Elegi como pagar tu compra.</p>

            <div class="payment__methods">
                <button
                    v-for="method in methods"
                    :key="method.code"
                    type="button"
                    class="payment__method-card"
                    @click="choose(method)"
                >
                    <i
                        class="bi fs-3"
                        :class="method.code === 'mercadopago' ? 'bi-bank' : 'bi-qr-code-scan'"
                    ></i>
                    <span class="fw-semibold">{{ method.name }}</span>
                    <span class="small text-muted-2">
                        {{ method.code === 'mercadopago' ? 'Tarjeta, Rapipago, Pago Facil' : 'QR de Multipago' }}
                    </span>
                </button>
            </div>

            <p v-if="methods.length === 0" class="small text-muted-2">
                Este evento no tiene medios de pago habilitados por el momento.
            </p>
        </template>

        <div v-else-if="chosen.type === 'qr' && qr" class="payment__qr-wrap">
            <div v-if="paid" class="alert alert-success small">
                <i class="bi bi-check-circle-fill me-1"></i>
                Pago confirmado. Preparando tus entradas...
            </div>

            <div v-else-if="cancelled" class="alert alert-warning small">
                La orden se cancelo. Podes reintentar el pago.
                <button type="button" class="btn btn-link btn-sm p-0 ms-1" @click="goBackToList">
                    Volver a elegir medio
                </button>
            </div>

            <div v-else class="payment__qr-card et-surface-raised p-4 text-center">
                <p class="small text-muted-2 mb-3">
                    Escaneá el QR con la app de Multipago o abri el enlace. La
                    orden se confirma sola cuando Multipago notifique el pago.
                </p>

                <img
                    :src="`tickets-portal/api/orders/${orderId}/multipago-qr.svg?size=380`"
                    alt="QR Multipago"
                    class="payment__qr-img"
                >

                <div class="payment__qr-info">
                    <div>
                        <span class="small text-muted-2">Importe</span>
                        <strong>{{ formatAmount(qr.amount) }}</strong>
                    </div>
                    <div>
                        <span class="small text-muted-2">Codigo</span>
                        <code class="font-monospace">{{ qr.code }}</code>
                    </div>
                </div>

                <a
                    :href="qr.url"
                    target="_blank"
                    rel="noopener"
                    class="btn btn-et-primary mt-3"
                >
                    <i class="bi bi-box-arrow-up-right me-1"></i>
                    Abrir Multipago
                </a>

                <p class="fake-link small mt-3 mb-0" @click="emit('navigate', 'my-orders')">
                    Ver mis ordenes
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.payment {
    max-width: 560px;
    margin: 0 auto;
    padding: 1rem 0;
}

.payment__title {
    font-size: 1.2rem;
    font-weight: 700;
    margin-bottom: 0.75rem;
}

.payment__methods {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.75rem;
}

.payment__method-card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.35rem;
    padding: 1.1rem;
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius);
    background: var(--et-bg-elevated);
    color: var(--et-text);
    text-align: left;
    transition: border-color var(--et-transition), box-shadow var(--et-transition);
}

.payment__method-card:hover {
    border-color: var(--et-primary);
    box-shadow: 0 2px 10px var(--et-shadow);
}

.payment__qr-card {
    text-align: center;
}

.payment__qr-img {
    width: min(100%, 380px);
    height: auto;
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius);
    background: #fff;
}

.payment__qr-info {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    margin-top: 1rem;
}

.payment__qr-info div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    border-bottom: 1px dashed var(--et-border);
    padding-bottom: 0.35rem;
}

.fake-link {
    color: var(--et-primary, #0d6efd);
    cursor: pointer;
    text-decoration: underline;
}
</style>
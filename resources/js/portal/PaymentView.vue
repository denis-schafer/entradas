<script setup>
/**
 * Eleccion del medio de pago + screens de cada medio.
 *
 * Llega desde EventDetailView con el order_id recien creado. Pide la lista de
 * medios habilitados para el evento y deja elegir:
 *   - MercadoPago: crea la preference y salta al checkout de MP.
 *   - Multipago: expone dos modalidades independientes (QR y boton de pago);
 *     ambas usan el mismo codigo PUM. La orden se confirma por webhook o por
 *     validacion.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { redirectToMercadoPago } from '../mercadoPago.js';
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
const expiresAt = ref(null);

const expiresLabel = computed(() => {
    if (!expiresAt.value) {
        return '';
    }

    const date = new Date(expiresAt.value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString('es-AR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
});

const qr = ref(null);
const paid = ref(false);
const cancelled = ref(false);
const copied = ref(false);

let pollTimer = null;

async function loadMethods() {
    loading.value = true;

    try {
        const { data } = await api.get(`tickets-portal/api/orders/${orderId}/payment-methods`);

        methods.value = data.methods || [];
        expiresAt.value = data.expires_at || null;
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
    } else if (method.variant === 'button') {
        await startMultipagoButton();
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

        redirectToMercadoPago(target);
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
            `tickets-portal/api/orders/${orderId}/multipago-code?variant=qr`
        );

        qr.value = data;
        startPolling();
    } catch (err) {
        error.value = toError(err).message;
        toast(error.value, 'danger');
    }
}

/**
 * Boton de pago: en vez de mostrar el codigo intermedio, abre directamente el
 * link de Multipago. La pestana se abre en blanco dentro del mismo click
 * (gesto del usuario) porque el navegador bloquea window.open despues de un
 * await. Si igual queda bloqueada, la pantalla muestra el codigo con el boton
 * "Abrir Multipago" como respaldo.
 */
async function startMultipagoButton() {
    const tab = window.open('', '_blank');

    try {
        const { data } = await api.get(
            `tickets-portal/api/orders/${orderId}/multipago-code?variant=button`
        );

        qr.value = data;
        startPolling();

        if (tab) {
            tab.location.href = data.url;
        }
    } catch (err) {
        tab?.close();
        error.value = toError(err).message;
        toast(error.value, 'danger');
    }
}

async function copyCode() {
    try {
        await navigator.clipboard.writeText(qr.value.code);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch {
        toast('No se pudo copiar', 'warning');
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
    copied.value = false;
}

function methodIcon(method) {
    if (method.type === 'redirect') {
        return 'bi-bank';
    }

    return method.variant === 'button' ? 'bi-upc-scan' : 'bi-qr-code-scan';
}

function isMultipago(method) {
    return method && String(method.type).startsWith('multipago');
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

        <p v-if="expiresLabel" class="text-muted-2 small mb-3">
            <i class="bi bi-clock me-1"></i>
            Esta reserva vence el {{ expiresLabel }}. Despues de esa hora se libera el stock.
        </p>

        <div v-if="loading" class="d-flex justify-content-center py-5">
            <div class="spinner-border text-primary"></div>
        </div>

        <div v-else-if="error" class="alert alert-danger small">
            {{ error }}
            <button type="button" class="btn btn-link btn-sm p-0 ms-1" @click="emit('navigate', 'events')">
                Ver eventos
            </button>
        </div>

        <template v-else-if="!chosen">
            <p class="text-muted-2 small">Elegi como pagar tu compra.</p>

            <div class="payment__methods">
                <button
                    v-for="method in methods"
                    :key="`${method.code}-${method.variant || ''}`"
                    type="button"
                    class="payment__method-card"
                    @click="choose(method)"
                >
                    <i class="bi fs-3" :class="methodIcon(method)"></i>
                    <span class="fw-semibold">{{ method.name }}</span>
                    <span class="small text-muted-2">{{ method.subtitle }}</span>
                </button>
            </div>

            <p v-if="methods.length === 0" class="small text-muted-2">
                Este evento no tiene medios de pago habilitados por el momento.
            </p>
        </template>

        <div v-else-if="isMultipago(chosen) && qr" class="payment__qr-wrap">
            <div v-if="paid" class="alert alert-success small">
                <i class="bi bi-check-circle-fill me-1"></i>
                Pago confirmado. Preparando tus entradas...
            </div>

            <div v-else-if="cancelled" class="alert alert-warning small">
                La reserva venció o la orden se canceló. Podes reintentar el pago.
                <button type="button" class="btn btn-link btn-sm p-0 ms-1" @click="goBackToList">
                    Volver a elegir medio
                </button>
            </div>

            <div v-else class="payment__qr-card et-surface-raised p-4 text-center">
                <template v-if="chosen.variant === 'qr'">
                    <p class="small text-muted-2 mb-3">
                        Escaneá el QR con cualquier billetera virtual. La orden
                        se confirma sola cuando Multipago notifique el pago.
                    </p>

                    <img
                        :src="`tickets-portal/api/orders/${orderId}/multipago-qr.svg?size=380`"
                        alt="QR Multipago"
                        class="payment__qr-img"
                    >
                </template>

                <template v-else>
                    <p class="small text-muted-2 mb-3">
                        Presentá este código de barras para pagar. La orden se
                        confirma sola cuando Multipago notifique el pago.
                    </p>

                    <code class="payment__barcode">{{ qr.code }}</code>

                    <button
                        type="button"
                        class="btn btn-et-ghost btn-sm mt-2"
                        @click="copyCode"
                    >
                        <i class="bi" :class="copied ? 'bi-check' : 'bi-clipboard'"></i>
                        {{ copied ? 'Copiado' : 'Copiar código' }}
                    </button>
                </template>

                <div class="payment__qr-info">
                    <div>
                        <span class="small text-muted-2">Importe</span>
                        <strong>{{ formatAmount(qr.amount) }}</strong>
                    </div>
                    <div>
                        <span class="small text-muted-2">Vencimiento</span>
                        <strong>{{ qr.due }}</strong>
                    </div>
                </div>

                <a
                    v-if="qr.url"
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

.payment__barcode {
    display: block;
    padding: 1rem;
    border: 1px dashed var(--et-border);
    border-radius: var(--et-radius);
    background: #fff;
    font-size: 1.05rem;
    letter-spacing: 0.08em;
    word-break: break-all;
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
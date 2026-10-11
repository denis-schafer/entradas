<script setup>
/**
 * Entradas de una venta, en modo publico.
 *
 * Es la pantalla que ve el comprador cuando abre el link que le mando el
 * vendedor por WhatsApp. No hay sesion aca: se sirven los datos de ESA venta
 * y su QR directo, sin tocar la cuenta. Ultimo boton: entrar al portal, que
 * pide login normal.
 */
import { ref, onMounted } from 'vue';
import api, { toError } from '../api.js';

const props = defineProps({
    token: { type: String, required: true },
});

const emit = defineEmits(['navigate']);

const loading = ref(true);
const error = ref('');
const data = ref(null);

async function load() {
    loading.value = true;
    error.value = '';

    try {
        const { data: payload } = await api.get(`tickets-portal/api/public/entry/${props.token}`);
        data.value = payload;
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

function qrUrl(ticket) {
    return `/tickets-portal/api/public/entry/${props.token}/${ticket.id}/qr.svg?size=520`;
}

function formatDate(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleDateString('es-AR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

function formatTime(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
}

function statusHint(ticket) {
    if (ticket.status === 'used') {
        return 'Esta entrada ya fue utilizada.';
    }

    return '';
}

function qrAvailable(ticket) {
    return data.value?.order?.status === 'paid' && ticket.status !== 'cancelled';
}

onMounted(load);
</script>

<template>
    <div class="entry">
        <p v-if="error" class="entry__notice" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <template v-else-if="data">
            <header class="mb-4">
                <h1 class="h3 fw-bold mb-1">{{ data.order.event_name }}</h1>
                <p class="text-muted-2 mb-0">
                    <i v-if="data.order.starts_at" class="bi bi-calendar-event me-1"></i>
                    {{ formatDate(data.order.starts_at) }}
                    <template v-if="formatTime(data.order.starts_at)">
                        {{ formatTime(data.order.starts_at) }}
                    </template>
                    <span v-if="data.order.location" class="ms-2 text-truncate">
                        <i class="bi bi-geo-alt me-1"></i>{{ data.order.location }}
                    </span>
                </p>
            </header>

            <p
                v-if="data.order.status !== 'paid'"
                class="entry__notice mb-4"
            >
                El QR aparece cuando se confirme el pago.
            </p>

            <div v-if="!data.tickets.length" class="et-surface p-4 text-center text-muted-2">
                No hay entradas para mostrar de esta venta.
            </div>

            <div v-else class="row g-3">
                <div v-for="ticket in data.tickets" :key="ticket.id" class="col-12 col-md-6 col-xl-4">
                    <article class="eticket et-surface">
                        <div class="eticket__body">
                            <h2 class="h6 fw-bold mb-1 text-truncate">{{ data.order.event_name }}</h2>
                            <p class="eticket__type mb-2">
                                <span
                                    v-if="ticket.wristband_color || ticket.type_wristband_color"
                                    class="eticket__dot"
                                    :style="{ background: ticket.wristband_color || ticket.type_wristband_color }"
                                ></span>
                                {{ ticket.type_name }}
                            </p>
                            <p v-if="ticket.used_at" class="small text-faint mb-0 numeric">
                                <i class="bi bi-check2-circle me-1"></i>
                                Usada el {{ new Date(ticket.used_at).toLocaleString('es-AR') }}
                            </p>
                        </div>

                        <div class="eticket__qr">
                            <img v-if="qrAvailable(ticket)" :src="qrUrl(ticket)" :alt="`QR de ${data.order.event_name}`" class="eticket__qr-img">
                            <p v-if="qrAvailable(ticket)" class="uuid small mt-2 mb-0">{{ ticket.uuid }}</p>
                            <p v-if="statusHint(ticket)" class="small text-faint mb-0">
                                {{ statusHint(ticket) }}
                            </p>
                        </div>
                    </article>
                </div>
            </div>

            <div class="entry__cta mt-4">
                <button class="btn btn-et-ghost" @click="emit('navigate', 'login')">
                    <i class="bi bi-box-arrow-in-right me-1"></i>
                    Ingresar a mi cuenta
                </button>
            </div>
        </template>
    </div>
</template>

<style scoped>
.entry {
    min-height: 45vh;
    max-width: 920px;
    margin: 0 auto;
}

.entry__notice {
    padding: 0.6rem 0.8rem;
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius-sm);
    background: var(--et-bg-elevated);
}

.eticket {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    grid-template-areas:
        "body"
        "qr";
    gap: 0.85rem;
    padding: 0.85rem 1rem;
    align-items: start;
}

.eticket__body {
    grid-area: body;
    min-width: 0;
}

.eticket__type {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    color: var(--et-text-muted);
    font-size: 0.875rem;
}

.eticket__dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 1px solid rgb(255 255 255 / 25%);
    flex-shrink: 0;
}

.eticket__qr {
    grid-area: qr;
    padding-top: 0.85rem;
    border-top: 1px dashed var(--et-border-strong);
    text-align: center;
}

.eticket__qr-img {
    width: 100%;
    max-width: 220px;
    border-radius: var(--et-radius-sm);
    background: #fff;
    padding: 0.6rem;
}

.uuid {
    color: var(--et-text-faint);
    word-break: break-all;
    font-size: 0.7rem;
}

.entry__cta {
    text-align: center;
}
</style>
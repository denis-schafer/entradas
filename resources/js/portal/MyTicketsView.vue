<script setup>
/**
 * Mis entradas: los boletos del comprador, con su QR.
 *
 * El QR se pide a /my-tickets/{id}/qr.svg, que devuelve SVG. Va directo a un
 * <img> porque el backend ya lo dibuja con un renderizador sin dependencias de
 * PHP; el navegador solo lo muestra. Asi no hay una libreria de QR en el bundle.
 *
 * El endpoint solo responde si la orden esta pagada, asi que un boleto de una
 * orden pendiente no se puede ni ver. AcÃ¡ no se oculta a proposito: el backend
 * es la frontera.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { startPolling, stopPolling } from '../realtime.js';
import StatusBadge from '../ui/StatusBadge.vue';
import EmptyState from '../ui/EmptyState.vue';
import ImageFlyer from '../ui/ImageFlyer.vue';
import { toast } from '../ui/toast.js';

const props = defineProps({
    user: { type: Object, default: null },
    config: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['navigate']);

const tickets = ref([]);
const loading = ref(true);
const error = ref('');
const openQr = ref(null);

async function load() {
    try {
        const { data } = await api.get('tickets-portal/api/my-tickets');

        tickets.value = data;
        error.value = '';
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

const usable = computed(() => tickets.value.filter((t) => t.order_status === 'paid'));

/**
 * El QR solo existe para ordenes pagadas. Para el resto se muestra el motivo,
 * porque un "no disponible" generico no le dice al comprador que hacer.
 */
function qrAvailable(ticket) {
    return ticket.order_status === 'paid' && ticket.qr_payload && ticket.status !== 'cancelled';
}

function qrUrl(ticket) {
    return `/tickets-portal/api/my-tickets/${ticket.id}/qr.svg?size=520`;
}

function qrHint(ticket) {
    if (ticket.order_status !== 'paid') {
        return 'El QR aparece cuando el pago este confirmado.';
    }

    if (ticket.status === 'used') {
        return 'Esta entrada ya fue utilizada.';
    }

    if (ticket.status === 'cancelled') {
        return 'Esta entrada quedo anulada.';
    }

    return '';
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

function toggleQr(ticket) {
    openQr.value = openQr.value === ticket.id ? null : ticket.id;
}

function downloadQr(ticket) {
    // Se descarga el SVG servido por el backend, que es el mismo que se escanea.
    const link = document.createElement('a');

    link.href = qrUrl(ticket);
    link.download = `entrada-${ticket.uuid.slice(0, 8)}.svg`;
    document.body.appendChild(link);
    link.click();
    link.remove();

    toast('QR descargado', 'success');
}

/*
| Imprimir con una ventana nueva y no con innerHTML: el nombre del evento y el
| del tipo los escribe quien organiza el evento, y meterlos en una plantilla
| HTML los convertiria en un XSS servido desde la pagina del comprador. Con
| textContent no hay forma de que escapen del texto que son.
*/
function printQr(ticket) {
    const win = window.open('', '_blank', 'width=460,height=680');

    if (!win) {
        toast('El navegador bloqueo la ventana de impresion', 'warning');

        return;
    }

    const style = win.document.createElement('style');

    style.textContent = `
        body { font-family: sans-serif; text-align: center; padding: 24px; }
        h2 { margin: 0 0 4px; font-size: 18px; }
        .meta { margin: 0 0 16px; color: #555; font-size: 13px; }
        img { width: 320px; height: 320px; }
        .uuid { margin-top: 16px; font-size: 11px; color: #666; }
    `;

    const heading = win.document.createElement('h2');
    const meta = win.document.createElement('p');
    const image = win.document.createElement('img');
    const uuid = win.document.createElement('p');

    meta.className = 'meta';
    meta.textContent = ticket.type_name || '';
    image.src = qrUrl(ticket);
    image.alt = ticket.event_name || 'Entrada';
    uuid.className = 'uuid';
    uuid.textContent = ticket.uuid;

    win.document.head.appendChild(style);
    win.document.body.append(heading, meta, image, uuid);

    heading.textContent = ticket.event_name || 'Entrada';

    // Imprimir antes de que el QR cargara sacaba una hoja en blanco: el src
    // recien asignado todavia no termino de bajarse. Se espera al evento y
    // solo ahi se llama a print(). El error tambien imprime: preferimos una
    // hoja sin QR a una hoja que nunca sale.
    let printed = false;

    const go = () => {
        if (printed) {
            return;
        }

        printed = true;
        win.focus();
        win.print();
    };

    image.addEventListener('load', go);
    image.addEventListener('error', go);

    if (image.complete) {
        go();
    }
}

onMounted(async () => {
    await load();

    // Las entradas del comprador cambian cuando un pago se confirma o cuando
    // el panel anula una. El canal privado por orden no sirve aca (esta pantalla
    // no tiene un token de orden propio, tiene la lista), asi que el refresh
    // va por polling, que ya esta pausado con la pestana oculta.
    startPolling(load);
});

onBeforeUnmount(() => {
    stopPolling();
});
</script>

<template>
    <div class="tickets">
        <header class="mb-4">
            <h1 class="h3 fw-bold mb-1">Mis entradas</h1>
            <p class="text-muted-2 mb-0">
                {{ usable.length }} {{ usable.length === 1 ? 'entrada disponible' : 'entradas disponibles' }}
            </p>
        </header>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <EmptyState
            v-else-if="!tickets.length"
            icon="bi-ticket-perforated"
            title="Todavia no tenes entradas"
            hint="Cuando compres, van a aparecer aca con su QR."
        >
            <button class="btn btn-et-primary btn-sm" @click="emit('navigate', 'events')">Ver eventos</button>
        </EmptyState>

        <div v-else class="row g-3">
            <div v-for="ticket in tickets" :key="ticket.id" class="col-12 col-md-6 col-xl-4">
                <article class="eticket et-surface">
                    <div class="eticket__thumb-wrap">
                        <ImageFlyer
                            :src="ticket.type_image_path || ticket.event_cover"
                            :alt="ticket.event_name"
                            thumb-class="eticket__thumb"
                        />

                        <div class="eticket__status">
                            <StatusBadge :status="ticket.status" />
                        </div>
                    </div>

                    <div class="eticket__body">
                        <h2 class="h6 fw-bold mb-1 text-truncate">{{ ticket.event_name }}</h2>

                        <p class="eticket__type mb-2">
                            <span
                                v-if="ticket.wristband_color || ticket.type_wristband_color"
                                class="eticket__dot"
                                :style="{ background: ticket.wristband_color || ticket.type_wristband_color }"
                            ></span>
                            {{ ticket.type_name }}
                        </p>

                        <p v-if="ticket.starts_at" class="text-muted-2 small mb-1 numeric">
                            <i class="bi bi-calendar-event me-1"></i>
                            {{ formatDate(ticket.starts_at) }}
                            <span v-if="formatTime(ticket.starts_at)">
                                {{ formatTime(ticket.starts_at) }}
                            </span>
                        </p>

                        <p v-if="ticket.location" class="text-muted-2 small mb-0 text-truncate">
                            <i class="bi bi-geo-alt me-1"></i>{{ ticket.location }}
                        </p>

                        <p v-if="ticket.used_at" class="small mt-2 mb-0 text-faint numeric">
                            <i class="bi bi-check2-circle me-1"></i>
                            Usada el {{ new Date(ticket.used_at).toLocaleString('es-AR') }}
                        </p>
                    </div>

                    <div class="eticket__qr" v-if="openQr === ticket.id">
                        <template v-if="qrAvailable(ticket)">
                            <img :src="qrUrl(ticket)" :alt="`QR de ${ticket.event_name}`" class="eticket__qr-img">
                            <p class="uuid small mt-2 mb-2">{{ ticket.uuid }}</p>
                            <div class="d-flex gap-2">
                                <button class="btn btn-et-ghost btn-sm" @click="downloadQr(ticket)">
                                    <i class="bi bi-download me-1"></i>Descargar
                                </button>
                                <button class="btn btn-et-ghost btn-sm" @click="printQr(ticket)">
                                    <i class="bi bi-printer me-1"></i>Imprimir
                                </button>
                            </div>
                        </template>

                        <p v-else class="small text-faint mb-0">
                            {{ qrHint(ticket) }}
                        </p>
                    </div>

                    <div class="eticket__foot">
                        <button
                            class="btn btn-sm w-100"
                            :class="openQr === ticket.id ? 'btn-et-ghost' : 'btn-et-primary'"
                            @click="toggleQr(ticket)"
                        >
                            <i class="bi" :class="openQr === ticket.id ? 'bi-chevron-up' : 'bi-qr-code'" />
                            <span class="ms-1">
                                {{ openQr === ticket.id ? 'Ocultar QR' : 'Ver QR' }}
                            </span>
                        </button>
                    </div>
                </article>
            </div>
        </div>
    </div>
</template>

<style scoped>
.tickets {
    min-height: 40vh;
}

.eticket {
    /*
    | Grid de dos filas / dos columnas: miniatura y body arriba, QR y footer
    | abajo a todo lo ancho. Asi la imagen no domina la tarjeta como antes,
    | que era un banner 16:9 full-width, y pasa a ser un thumbnail al costado
    | de los datos (click -> lightbox).
    */
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    grid-template-areas:
        "thumb body"
        "qr qr"
        "foot foot";
    gap: 0.85rem 0.85rem;
    padding: 0.85rem 1rem;
    align-items: start;
}

.eticket__thumb-wrap {
    grid-area: thumb;
    position: relative;
    align-self: start;
}

/*
| :deep() para que la clase thumb-class llegue al <button> raiz del
| componente ImageFlyer (ver nota en EventDetailView.vue).
*/
.eticket__thumb-wrap :deep(.eticket__thumb) {
    width: 84px;
    height: 84px;
}

.eticket__status {
    position: absolute;
    top: -6px;
    right: -6px;
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

.eticket__foot {
    grid-area: foot;
}
</style>
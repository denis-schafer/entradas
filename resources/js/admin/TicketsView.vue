<script setup>
/**
 * Entradas del panel.
 *
 * El listado plano sirve para dos cosas: encontrar una entrada puntual (el
 * comprador perdio el QR) y auditar el estado de todas las de un evento.
 *
 * El QR se regenera siempre desde qr_payload en el servidor: nunca se inventa
 * ni se recompone en el navegador, porque la firma es lo que el escaner valida.
 */
import { ref, reactive, watch, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { listen, startPolling, stopPolling } from '../realtime.js';
import StatusBadge from '../ui/StatusBadge.vue';
import EmptyState from '../ui/EmptyState.vue';
import { toast } from '../ui/toast.js';

const tickets = ref([]);
const events = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });

const filters = reactive({ search: '', status: '', order_status: 'paid', event_id: '', page: 1 });
const loading = ref(true);
const error = ref('');

/*
| El default es "Pagadas" porque son las entradas que realmente tienen valor
| para el operador (auditar asistencia, reimprimir QRs, ver si se entrego
| pulsera). Las pendientes quedan reservadas para el caso puntual: las hay
| en la DB cuando una reserva se creo sin terminar el pago, y conviene poder
| verlas sin que sean lo primero que aparece.
*/
const STATUSES = [
    { value: 'valid', label: 'Validas' },
    { value: 'used', label: 'Usadas' },
    { value: 'cancelled', label: 'Anuladas' },
    { value: '', label: 'Todos los estados' },
];

const ORDER_STATUSES = [
    { value: 'paid', label: 'Pagadas' },
    { value: 'pending', label: 'Pendientes' },
    { value: 'cancelled', label: 'Canceladas' },
    { value: '', label: 'Todas las ordenes' },
];

async function load() {
    try {
        const { data } = await api.get('tickets-admin/tickets', {
            search: filters.search || undefined,
            status: filters.status || undefined,
            order_status: filters.order_status || undefined,
            event_id: filters.event_id || undefined,
            page: filters.page,
        });

        tickets.value = data.data || [];
        meta.value = {
            current_page: data.current_page || 1,
            last_page: data.last_page || 1,
            total: data.total || 0,
        };
        error.value = '';
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

async function loadEvents() {
    try {
        const { data } = await api.get('tickets-admin/events', { all: 1 });

        events.value = data;
    } catch {
        // El filtro por evento es una comodidad, no un requisito.
    }
}

function formatDate(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleString('es-AR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function qrUrl(ticket) {
    return `/tickets-admin/tickets/${ticket.id}/qr.svg?size=280`;
}

function canShowQr(ticket) {
    /*
    | Solo se genera/muestra QR a entradas cobradas:
    |   - status !== 'cancelled': una entrada anulada no se imprime, mostrarla
    |     invita a escanear algo que el backend va a rechazar.
    |   - order_status === 'paid': si la orden esta pendiente, el comprador
    |     todavia no pago. Mostrar el QR aca seria equivalente a regalar la
    |     entrada al staff antes de confirmar el cobro.
    */
    return ticket.status !== 'cancelled' && ticket.order_status === 'paid';
}

function downloadQr(ticket) {
    const link = document.createElement('a');

    link.href = qrUrl(ticket);
    link.download = `entrada-${ticket.uuid.slice(0, 8)}.svg`;
    document.body.appendChild(link);
    link.click();
    link.remove();

    toast('QR descargado', 'success');
}

async function cancelTicket(ticket) {
    try {
        await api.post(`tickets-admin/tickets/${ticket.id}/cancel`);

        toast('Entrada anulada', 'success');
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    }
}

async function giveWristband(ticket, giveNext = true) {
    try {
        await api.post(`tickets-admin/tickets/${ticket.id}/wristband`, {
            give: giveNext,
        });

        toast(giveNext ? 'Pulsera registrada' : 'Entrega de pulsera revertida', 'success');
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    }
}

function goToPage(page) {
    filters.page = page;
    load();
}

let searchTimer = null;

// Cada listen devuelve su propio apagado. Se guardan para soltar los canales
// al salir de la vista.
let unsubs = [];

watch(() => filters.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        filters.page = 1;
        load();
    }, 350);
});

watch([() => filters.status, () => filters.order_status, () => filters.event_id], () => {
    filters.page = 1;
    load();
});

onMounted(async () => {
    await Promise.all([load(), loadEvents()]);

    // Un escaneo cambia el status de una entrada que puede estar en la pagina
    // abierta, asi que se refresca en vez de esperar al proximo polling.
    unsubs.push(listen('tickets.admin', 'ticket.scanned', () => {
        if (filters.page === 1) {
            load();
        }
    }));

    startPolling(load);
});

onBeforeUnmount(() => {
    // Sin esto, cada ida y vuelta al listado dejaba un listener mas en el
    // canal y un refresh se multiplicaba por la cantidad de veces que se entro.
    unsubs.forEach((off) => off());
    unsubs = [];

    stopPolling();
});
</script>

<template>
    <div>
        <header class="mb-3">
            <h2 class="h5 fw-bold mb-0">Entradas</h2>
            <p class="text-muted-2 small mb-0">{{ meta.total }} entradas emitidas</p>
        </header>

        <div class="et-surface-raised p-3 mb-3">
            <div class="row g-2">
                <div class="col-12 col-lg-5">
                    <label class="form-label" for="search">Buscar</label>
                    <input
                        id="search"
                        v-model="filters.search"
                        type="search"
                        class="form-control"
                        placeholder="UUID, nombre, email o DNI"
                    >
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label" for="status">Estado</label>
                    <select id="status" v-model="filters.status" class="form-select">
                        <option v-for="option in STATUSES" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label" for="order_status">Orden</label>
                    <select id="order_status" v-model="filters.order_status" class="form-select">
                        <option v-for="option in ORDER_STATUSES" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div class="col-12 col-lg-3">
                    <label class="form-label" for="event">Evento</label>
                    <select id="event" v-model="filters.event_id" class="form-select">
                        <option value="">Todos</option>
                        <option v-for="event in events" :key="event.id" :value="event.id">
                            {{ event.name }}
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <EmptyState
            v-else-if="!tickets.length"
            icon="bi-ticket-perforated"
            title="No hay entradas"
            hint="Las entradas se generan al crear una orden."
        />

        <div v-else class="row g-2">
            <div v-for="ticket in tickets" :key="ticket.id" class="col-12 col-md-6 col-xl-4">
                <article class="ticket et-surface">
                    <header class="ticket__head">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate">{{ ticket.type_name || 'Entrada' }}</div>
                            <div class="small text-faint text-truncate">{{ ticket.event_name }}</div>
                        </div>
                        <StatusBadge :status="ticket.status" />
                    </header>

                    <div class="ticket__body">
                        <div class="small text-muted-2 text-truncate">
                            {{ ticket.buyer_name }}
                            <div class="text-faint">{{ ticket.buyer_email }}</div>
                        </div>

                        <div class="small text-faint numeric uuid">{{ ticket.uuid }}</div>

                        <div v-if="ticket.order_status === 'pending'" class="small" style="color: var(--et-warning)">
                            <i class="bi bi-clock-history me-1"></i>Orden pendiente
                        </div>

                        <div v-if="ticket.used_at" class="small text-faint numeric">
                            Usada {{ formatDate(ticket.used_at) }}
                        </div>
                        <div v-else class="small text-faint numeric">
                            Emitida {{ formatDate(ticket.created_at) }}
                        </div>

                        <div v-if="ticket.wristband_given" class="small text-faint">
                            <i class="bi bi-circle-fill me-1" style="font-size: 0.4rem"
                                :style="{ color: ticket.wristband_color || 'var(--et-text-faint)' }"></i>
                            Pulsera entregada
                        </div>
                    </div>

                    <img
                        v-if="canShowQr(ticket)"
                        :src="qrUrl(ticket)"
                        :alt="`QR ${ticket.uuid}`"
                        class="ticket__qr"
                        loading="lazy"
                    >
                    <p v-else-if="ticket.status === 'cancelled'" class="small text-faint text-center my-2 mb-0">
                        Entrada anulada: no se imprime.
                    </p>
                    <p v-else class="small text-faint text-center my-2 mb-0">
                        Sin cobrar: QR no disponible.
                    </p>

                    <footer class="ticket__foot">
                        <button
                            v-if="canShowQr(ticket)"
                            class="btn btn-et-ghost btn-sm"
                            @click="downloadQr(ticket)"
                        >
                            <i class="bi bi-download me-1"></i>QR
                        </button>

                        <button
                            v-if="ticket.status === 'valid' && !ticket.wristband_given && ticket.order_status === 'paid'"
                            class="btn btn-et-ghost btn-sm"
                            @click="giveWristband(ticket, true)"
                        >
                            Pulsera
                        </button>

                        <button
                            v-if="ticket.wristband_given"
                            class="btn btn-et-ghost btn-sm"
                            title="Quitar la pulsera entregada (caso de error)"
                            @click="giveWristband(ticket, false)"
                        >
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Quitar pulsera
                        </button>

                        <button
                            v-if="ticket.status === 'valid'"
                            class="btn btn-et-ghost btn-sm btn-danger-soft"
                            @click="cancelTicket(ticket)"
                        >
                            Anular
                        </button>
                    </footer>
                </article>
            </div>
        </div>

        <nav v-if="meta.last_page > 1" class="pager">
            <button
                class="btn btn-et-ghost btn-sm"
                :disabled="meta.current_page <= 1"
                @click="goToPage(meta.current_page - 1)"
            >
                Anterior
            </button>
            <span class="small text-muted-2 numeric">{{ meta.current_page }} / {{ meta.last_page }}</span>
            <button
                class="btn btn-et-ghost btn-sm"
                :disabled="meta.current_page >= meta.last_page"
                @click="goToPage(meta.current_page + 1)"
            >
                Siguiente
            </button>
        </nav>
    </div>
</template>

<style scoped>
.ticket {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 0.85rem;
}

.ticket__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.5rem;
}

.ticket__body {
    padding: 0.6rem 0;
    flex: 1;
}

.ticket__qr {
    width: 100%;
    max-width: 160px;
    align-self: center;
    background: #fff;
    padding: 0.4rem;
    border-radius: var(--et-radius-sm);
    margin-bottom: 0.6rem;
}

.ticket__foot {
    display: flex;
    gap: 0.4rem;
    flex-wrap: wrap;
}

.uuid {
    font-size: 0.65rem;
    word-break: break-all;
}

.min-w-0 {
    min-width: 0;
}

.pager {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 1rem;
}
</style>
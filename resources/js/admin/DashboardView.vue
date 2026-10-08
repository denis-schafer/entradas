<script setup>
/**
 * Dashboard: lo primero que ve el operador al abrir el panel.
 *
 * Lo unico que cambia en vivo mientras se mira son los numeros, asi que todo
 * se apoya en el canal privado tickets.admin (solo admins autorizados) mas un
 * polling de respaldo. Si el websocket esta caido, los numeros igual se
 * actualizan solos cada 15 segundos.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { listen, startPolling, stopPolling } from '../realtime.js';
import StatCard from '../ui/StatCard.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import EmptyState from '../ui/EmptyState.vue';
import ImageFlyer from '../ui/ImageFlyer.vue';

const props = defineProps({
    event_id: { type: [Number, String], default: null },
});

const emit = defineEmits(['navigate']);

const data = ref(null);
const loading = ref(true);
const error = ref('');

// Filtro por evento, mismo estilo que Estadisticas: vacio = todos.
const events = ref([]);
const eventId = ref(props.event_id ? Number(props.event_id) : '');

/* Cada evento del panel pide un refresco. No se usan los payloads: digan lo
   que digan, la fuente de verdad es la respuesta del dashboard. */
let unsubscribers = [];

async function load() {
    try {
        const { data: payload } = await api.get('tickets-admin/dashboard', {
            event_id: eventId.value || undefined,
        });

        data.value = payload;
        error.value = '';
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

async function loadEvents() {
    try {
        const { data: payload } = await api.get('tickets-admin/events', { all: 1 });
        events.value = payload;
    } catch {
        // El filtro es una comodidad: sin la lista el panel se ve global.
    }
}

function applyEventFilter() {
    loading.value = true;
    load();
}

const currentEventName = computed(() =>
    eventId.value ? events.value.find((event) => event.id === eventId.value)?.name : 'Todos los eventos',
);

const revenue = computed(() => data.value?.revenue ?? 0);

function money(value) {
    return new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        maximumFractionDigits: 0,
    }).format(Number(value || 0));
}

function formatDate(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleDateString('es-AR', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatEventDate(value) {
    if (!value) {
        return 'Fecha a confirmar';
    }

    return new Date(value).toLocaleDateString('es-AR', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

onMounted(async () => {
    await Promise.all([load(), loadEvents()]);

    // El canal del admin. Cualquiera de estos eventos puede cambiar un numero
    // de esta pantalla, asi que los cinco usan la misma accion.
    const refresh = () => load();

    unsubscribers = [
        listen('tickets.admin', 'order.created', refresh),
        listen('tickets.admin', 'order.status', refresh),
        listen('tickets.admin', 'stock.changed', refresh),
        listen('tickets.admin', 'event.status', refresh),
        listen('tickets.admin', 'ticket.scanned', refresh),
    ];

    startPolling(load);
});

onBeforeUnmount(() => {
    unsubscribers.forEach((off) => off?.());
    unsubscribers = [];
    stopPolling();
});
</script>

<template>
    <div class="dash">
        <header class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
            <div>
                <h2 class="h5 fw-bold mb-0">Panel</h2>
                <p class="text-muted-2 small mb-0">{{ currentEventName }}</p>
            </div>

            <select
                v-model="eventId"
                class="form-select"
                style="max-width: 260px"
                @change="applyEventFilter"
            >
                <option value="">Todos los eventos</option>
                <option v-for="event in events" :key="event.id" :value="event.id">
                    {{ event.name }}
                </option>
            </select>
        </header>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <template v-else-if="data">
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3">
                    <StatCard
                        label="Recaudado"
                        :value="money(revenue)"
                        hint="Ordenes pagadas"
                        icon="bi-cash-stack"
                        tone="success"
                    />
                </div>

                <div class="col-6 col-lg-3">
                    <StatCard
                        label="Ordenes pagadas"
                        :value="data.orders.paid"
                        hint="Historico total"
                        icon="bi-check2-circle"
                        tone="primary"
                    />
                </div>

                <div class="col-6 col-lg-3">
                    <StatCard
                        label="Pendientes"
                        :value="data.orders.pending"
                        hint="Reservan stock"
                        icon="bi-hourglass-split"
                        tone="warning"
                    />
                </div>

                <div class="col-6 col-lg-3">
                    <StatCard
                        label="Escaneos"
                        :value="data.scans_last_30_days"
                        hint="Ultimos 30 dias"
                        icon="bi-upc-scan"
                    />
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12 col-lg-7">
                    <section class="et-surface-raised h-100">
                        <header class="panel__head">
                            <h2 class="panel__title">Ultimas ordenes</h2>
                            <button class="btn btn-link btn-sm p-0" @click="emit('navigate', 'orders')">
                                Ver todas
                            </button>
                        </header>

                        <EmptyState
                            v-if="!data.recent_orders?.length"
                            icon="bi-receipt"
                            title="Todavia no hay ordenes"
                            hint="Cuando alguien compre, va a aparecer aca."
                        />

                        <div v-else class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Orden</th>
                                        <th>Comprador</th>
                                        <th>Estado</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="order in data.recent_orders"
                                        :key="order.id"
                                        class="clickable"
                                        @click="emit('navigate', 'orders', { focus: order.id })"
                                    >
                                        <td class="numeric small">
                                            #{{ order.id }}
                                            <div class="text-faint">{{ formatDate(order.created_at) }}</div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold">{{ order.buyer_name }}</div>
                                            <div class="text-faint small text-truncate" style="max-width: 220px">
                                                {{ order.event_name }}
                                            </div>
                                        </td>
                                        <td><StatusBadge :status="order.status" /></td>
                                        <td class="text-end numeric fw-semibold">{{ money(order.total) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="col-12 col-lg-5">
                    <section class="et-surface-raised h-100">
                        <header class="panel__head">
                            <h2 class="panel__title">Proximos eventos</h2>
                            <button class="btn btn-link btn-sm p-0" @click="emit('navigate', 'events')">
                                Ver todos
                            </button>
                        </header>

                        <EmptyState
                            v-if="!data.upcoming_events?.length"
                            icon="bi-calendar-event"
                            title="Sin eventos programados"
                        />

                        <div v-else class="d-flex flex-column gap-2">
                            <article
                                v-for="event in data.upcoming_events"
                                :key="event.id"
                                class="mini clickable"
                                @click="emit('navigate', 'event-edit', { id: event.id })"
                            >
                                <ImageFlyer
                                    v-if="event.cover_image"
                                    :src="event.cover_image"
                                    :alt="`Portada: ${event.name}`"
                                    thumb-class="mini__img"
                                    @click.stop
                                />
                                <div v-else class="mini__img mini__img--blank"></div>

                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate">{{ event.name }}</div>
                                    <div class="small text-faint numeric">
                                        {{ formatEventDate(event.starts_at) }}
                                        <span v-if="event.location"> · {{ event.location }}</span>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </section>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.panel__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.9rem 1.1rem;
    border-bottom: 1px solid var(--et-border);
}

.panel__title {
    font-size: 0.95rem;
    font-weight: 700;
    margin: 0;
}

table {
    font-size: 0.875rem;
}

table th {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--et-text-faint);
    font-weight: 700;
    border-bottom: 1px solid var(--et-border);
}

.clickable {
    cursor: pointer;
}

.clickable:hover td {
    background: var(--et-surface-hover);
}

.mini {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 0.65rem;
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius-sm);
}

.mini:hover {
    border-color: var(--et-border-strong);
}

/*
| :deep() para que las clases thumb-class del ImageFlyer matcheen desde aca
| (el button raiz del ImageFlyer solo lleva su propio data-v, no el del
| componente padre, asi que las reglas scoped normales no aplican).
*/
.mini :deep(.mini__img) {
    width: 52px;
    height: 40px;
    border-radius: 6px;
    flex-shrink: 0;
}

.mini__img--blank {
    background: var(--et-surface-hover);
}

.min-w-0 {
    min-width: 0;
}
</style>
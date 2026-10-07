<script setup>
/**
 * Ordenes del panel.
 *
 * Es la pantalla de caja: se busca por nombre, email, DNI o id de pago de
 * MercadoPago. La busqueda es el caso de uso real (alguien compra y hay que
 * encontrarlo), asi que esta primero y busca en los cuatro campos.
 *
 * El detalle trae los boletos de la orden con su QR, que es lo que se necesita
 * cuando el comprador perdio su entrada.
 */
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { listen, startPolling, stopPolling } from '../realtime.js';
import StatusBadge from '../ui/StatusBadge.vue';
import EmptyState from '../ui/EmptyState.vue';
import { toast } from '../ui/toast.js';

const emit = defineEmits(['navigate']);

const orders = ref([]);
const events = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });

const filters = reactive({ search: '', status: '', event_id: '', page: 1 });
const loading = ref(true);
const error = ref('');

const detail = ref(null);
const detailBusy = ref(false);

const STATUSES = [
    { value: '', label: 'Todos' },
    { value: 'paid', label: 'Pagadas' },
    { value: 'pending', label: 'Pendientes' },
    { value: 'rejected', label: 'Rechazadas' },
    { value: 'cancelled', label: 'Canceladas' },
    { value: 'expired', label: 'Vencidas' },
];

async function load() {
    try {
        const { data } = await api.get('tickets-admin/orders', {
            search: filters.search || undefined,
            status: filters.status || undefined,
            event_id: filters.event_id || undefined,
            page: filters.page,
        });

        orders.value = data.data || [];
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

    return new Date(value).toLocaleString('es-AR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function paymentLabel(order) {
    if (order.payment_mode === 'installments') {
        return `${order.installment_count ?? '?'} cuotas`;
    }

    return '1 cuota';
}

async function openDetail(id) {
    detailBusy.value = true;
    detail.value = null;

    try {
        const { data } = await api.get(`tickets-admin/orders/${id}`);

        detail.value = data;
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        detailBusy.value = false;
    }
}

function closeDetail() {
    detail.value = null;
}

function qrUrl(ticketId) {
    return `/tickets-admin/tickets/${ticketId}/qr.svg?size=320`;
}

async function cancelTicket(ticket) {
    try {
        await api.post(`tickets-admin/tickets/${ticket.id}/cancel`);

        toast('Entrada anulada', 'success');

        await openDetail(detail.value.id);
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    }
}

const reconciling = ref(false);

async function reconcileOrder() {
    if (!detail.value || reconciling.value) {
        return;
    }

    reconciling.value = true;

    try {
        const { data } = await api.post(`tickets-admin/orders/${detail.value.id}/reconcile`);

        toast(data.message || 'Orden reconciliada', 'success');

        await openDetail(detail.value.id);
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        reconciling.value = false;
    }
}

function canCancel(ticket) {
    return ticket.status === 'valid';
}

async function giveWristband(ticket, giveNext = true) {
    try {
        await api.post(`tickets-admin/tickets/${ticket.id}/wristband`, {
            give: giveNext,
        });

        toast(giveNext ? 'Pulsera registrada' : 'Entrega de pulsera revertida', 'success');

        await openDetail(detail.value.id);
    } catch (err) {
        toast(toError(err).message, 'danger');
    }
}

function goToPage(target) {
    filters.page = target;
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

watch([() => filters.status, () => filters.event_id], () => {
    filters.page = 1;
    load();
});

onMounted(async () => {
    await Promise.all([load(), loadEvents()]);

    // Una orden que se paga aparece en la lista sin recargar: es el numero que
    // el operador mira para saber si la venta entro.
    unsubs.push(
        listen('tickets.admin', 'order.created', load),
        listen('tickets.admin', 'order.status', load),
    );

    startPolling(load);
});

onBeforeUnmount(() => {
    // Sin esto, cada ida y vuelta al listado dejaba dos listeners mas en el
    // canal y un refresh se multiplicaba por la cantidad de veces que se entro.
    unsubs.forEach((off) => off());
    unsubs = [];

    stopPolling();
});
</script>

<template>
    <div>
        <header class="mb-3">
            <h2 class="h5 fw-bold mb-0">Ordenes</h2>
            <p class="text-muted-2 small mb-0">{{ meta.total }} ordenes</p>
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
                        placeholder="Nombre, email, DNI o id de pago"
                    >
                </div>

                <div class="col-6 col-lg-3">
                    <label class="form-label" for="status">Estado</label>
                    <select id="status" v-model="filters.status" class="form-select">
                        <option v-for="option in STATUSES" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div class="col-6 col-lg-4">
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
            v-else-if="!orders.length"
            icon="bi-receipt"
            title="No hay ordenes"
            hint="No encontramos ordenes con esos filtros."
        />

        <div v-else class="et-surface-raised table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Orden</th>
                        <th>Comprador</th>
                        <th>Evento</th>
                        <th>Pago</th>
                        <th>Estado</th>
                        <th class="text-end">Total</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="order in orders" :key="order.id">
                        <td class="numeric small">
                            #{{ order.id }}
                            <div class="text-faint">{{ formatDate(order.created_at) }}</div>
                        </td>
                        <td>
                            <div class="fw-semibold">{{ order.buyer_name }}</div>
                            <div class="small text-faint">
                                {{ order.buyer_email }}
                                <span v-if="order.buyer_dni" class="numeric"> · {{ order.buyer_dni }}</span>
                            </div>
                        </td>
                        <td class="small">
                            {{ order.event_name || '—' }}
                            <div class="text-faint numeric">{{ order.ticket_count }} entradas</div>
                        </td>
                        <td class="small">{{ paymentLabel(order) }}</td>
                        <td><StatusBadge :status="order.status" /></td>
                        <td class="text-end numeric fw-semibold">{{ money(order.total) }}</td>
                        <td class="text-end">
                            <button class="btn btn-et-ghost btn-sm" @click="openDetail(order.id)">
                                Ver
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
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

        <!-- Detalle -->
        <div v-if="detailBusy || detail" class="drawer-wrap">
            <div class="drawer-wrap__scrim" @click="closeDetail"></div>

            <aside class="drawer">
                <header class="drawer__head">
                    <h3 class="h6 fw-bold mb-0">Orden #{{ detail?.id }}</h3>
                    <button class="btn btn-link btn-sm p-0" @click="closeDetail">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>

                <div v-if="detailBusy" class="text-center py-5">
                    <div class="spinner-border" role="status"></div>
                </div>

                <div v-else-if="detail" class="drawer__body">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <StatusBadge :status="detail.status" />
                        <span class="fw-bold numeric ms-auto">{{ money(detail.total) }}</span>
                    </div>

                    <dl class="detail-list">
                        <dt>Comprador</dt>
                        <dd>{{ detail.buyer_name }}</dd>

                        <dt>Email</dt>
                        <dd>{{ detail.buyer_email || '—' }}</dd>

                        <dt>DNI</dt>
                        <dd class="numeric">{{ detail.buyer_dni || '—' }}</dd>

                        <dt>Telefono</dt>
                        <dd class="numeric">{{ detail.buyer_phone || '—' }}</dd>

                        <dt>Evento</dt>
                        <dd>{{ detail.event_name || '—' }}</dd>

                        <dt>Creada</dt>
                        <dd class="numeric">{{ formatDate(detail.created_at) }}</dd>

                        <dt>Pagada</dt>
                        <dd class="numeric">{{ detail.paid_at ? formatDate(detail.paid_at) : '—' }}</dd>

                        <dt>Pago MP</dt>
                        <dd class="numeric">{{ detail.mp_payment_id || '—' }}</dd>

                        <dt>Forma</dt>
                        <dd>{{ paymentLabel(detail) }}</dd>
                    </dl>

                    <div v-if="detail.status === 'pending'" class="reconcile">
                        <p class="small text-muted-2 mb-2">
                            <i class="bi bi-info-circle me-1"></i>
                            Si el cliente pago pero la orden sigue pendiente (webhook no llego:
                            entorno local, deploy recien hecho, etc.), pedile a MP los pagos
                            y aplica el pago aprobado.
                        </p>
                        <button
                            class="btn btn-et-primary btn-sm w-100"
                            :disabled="reconciling"
                            @click="reconcileOrder"
                        >
                            <span v-if="reconciling" class="spinner-border spinner-border-sm me-2"></span>
                            {{ reconciling ? 'Consultando MercadoPago…' : 'Reconciliar con MercadoPago' }}
                        </button>
                    </div>

                    <h4 class="h6 fw-bold mt-4 mb-2">
                        Boletos ({{ detail.tickets?.length || 0 }})
                    </h4>

                    <div v-if="!detail.tickets?.length" class="small text-faint">Sin boletos.</div>

                    <article v-for="ticket in detail.tickets" :key="ticket.id" class="ticket">
                        <div class="ticket__info">
                            <div class="fw-semibold">{{ ticket.type_name }}</div>
                            <div class="small text-faint numeric uuid">{{ ticket.uuid.slice(0, 8) }}</div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <StatusBadge :status="ticket.status" />
                                <span v-if="ticket.wristband_given" class="small text-faint">
                                    <i class="bi bi-circle-fill me-1" style="font-size: 0.4rem"
                                        :style="{ color: ticket.wristband_color || 'var(--et-text-faint)' }"></i>
                                    Pulsera entregada
                                </span>
                            </div>
                        </div>

                        <img
                            v-if="detail.status === 'paid'"
                            :src="qrUrl(ticket.id)"
                            :alt="`QR ${ticket.uuid}`"
                            class="ticket__qr"
                        >

                        <div class="ticket__actions">
                            <button
                                v-if="canCancel(ticket)"
                                class="btn btn-et-ghost btn-sm btn-danger-soft"
                                @click="cancelTicket(ticket)"
                            >
                                Anular
                            </button>

                            <button
                                v-if="detail.status === 'paid' && !ticket.wristband_given"
                                class="btn btn-et-ghost btn-sm"
                                @click="giveWristband(ticket, true)"
                            >
                                Pulsera
                            </button>

                            <button
                                v-if="ticket.wristband_given && ticket.status !== 'used'"
                                class="btn btn-et-ghost btn-sm"
                                title="Quitar la pulsera entregada (caso de error)"
                                @click="giveWristband(ticket, false)"
                            >
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Quitar pulsera
                            </button>
                        </div>
                    </article>
                </div>
            </aside>
        </div>
    </div>
</template>

<style scoped>
table {
    font-size: 0.875rem;
}

table th {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--et-text-faint);
    font-weight: 700;
}

.pager {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 1rem;
}

.drawer-wrap__scrim {
    position: fixed;
    inset: 0;
    z-index: 50;
    background: rgb(0 0 0 / 45%);
}

.drawer {
    position: fixed;
    inset: 0 0 0 auto;
    z-index: 55;
    width: min(460px, 100%);
    background: var(--et-bg-elevated);
    border-left: 1px solid var(--et-border);
    display: flex;
    flex-direction: column;
}

.drawer__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.9rem 1.1rem;
    border-bottom: 1px solid var(--et-border);
}

.drawer__body {
    flex: 1;
    overflow-y: auto;
    padding: 1.1rem;
}

.detail-list {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 0.35rem 1rem;
    margin: 0;
    font-size: 0.875rem;
}

.detail-list dt {
    color: var(--et-text-faint);
    font-weight: 500;
}

.detail-list dd {
    margin: 0;
    text-align: right;
}

.ticket {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.75rem;
    align-items: center;
    padding: 0.7rem;
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius-sm);
    margin-bottom: 0.5rem;
}

.ticket__qr {
    width: 64px;
    height: 64px;
    background: #fff;
    padding: 0.25rem;
    border-radius: 4px;
}

.ticket__actions {
    grid-column: 1 / -1;
    display: flex;
    gap: 0.4rem;
    justify-content: flex-end;
}

.uuid {
    font-size: 0.7rem;
}

.reconcile {
    margin-top: 1rem;
    padding: 0.85rem 1rem;
    border: 1px dashed var(--et-warning, #b8860b);
    border-radius: var(--et-radius-sm);
    background: var(--et-warning-soft, rgba(184, 134, 11, 0.06));
}
</style>
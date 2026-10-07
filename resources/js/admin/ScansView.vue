<script setup>
/**
 * Historial de escaneos.
 *
 * Es la trazabilidad de la puerta: quien entro, cuando y con que resultado.
 * Por eso no se borra nada y los rechazos tambien quedan, aunque no tengan
 * boleto asociado.
 */
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { listen, startPolling, stopPolling } from '../realtime.js';
import EmptyState from '../ui/EmptyState.vue';

const events = ref([]);
const scans = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });

const filters = ref({ event_id: '', result: '', page: 1 });
const loading = ref(true);
const error = ref('');

let unsubscribe = null;

async function load() {
    try {
        const { data } = await api.get('tickets-admin/scans', {
            event_id: filters.value.event_id || undefined,
            result: filters.value.result || undefined,
            page: filters.value.page,
        });

        scans.value = data.data || [];
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
        // El filtro por evento es una comodidad: si falla, se sigue sin filtro.
    }
}

const RESULTS = [
    { value: '', label: 'Todos los resultados' },
    { value: 'valid', label: 'Validas' },
    { value: 'used', label: 'Ya usadas' },
    { value: 'wrong_event', label: 'De otro evento' },
    { value: 'invalid', label: 'Invalidas' },
];

function toneOf(result) {
    return {
        valid: 'success',
        used: 'warning',
        wrong_event: 'danger',
        invalid: 'danger',
    }[result] || 'muted';
}

function labelOf(result) {
    return {
        valid: 'Valida',
        used: 'Ya usada',
        wrong_event: 'Otro evento',
        invalid: 'Invalida',
    }[result] || result;
}

function formatDate(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleString('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function applyFilters() {
    filters.value.page = 1;
    load();
}

function goToPage(page) {
    filters.value.page = page;
    load();
}

watch(() => [filters.value.event_id, filters.value.result], applyFilters);

onMounted(async () => {
    await Promise.all([load(), loadEvents()]);

    // Un escaneo nuevo tiene que aparecer sin que el operador recargue: es la
    // unica forma de que dos puertas compartan el mismo estado.
    unsubscribe = listen('tickets.admin', 'ticket.scanned', () => {
        if (filters.value.page === 1) {
            load();
        }
    });

    startPolling(load);
});

onBeforeUnmount(() => {
    unsubscribe?.();
    stopPolling();
});
</script>

<template>
    <div>
        <header class="mb-3">
            <h2 class="h5 fw-bold mb-0">Escaneos</h2>
            <p class="text-muted-2 small mb-0">{{ meta.total }} registros</p>
        </header>

        <div class="et-surface-raised p-3 mb-3">
            <div class="row g-2">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="f-event">Evento</label>
                    <select id="f-event" v-model="filters.event_id" class="form-select">
                        <option value="">Todos</option>
                        <option v-for="event in events" :key="event.id" :value="event.id">
                            {{ event.name }}
                        </option>
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="f-result">Resultado</label>
                    <select id="f-result" v-model="filters.result" class="form-select">
                        <option v-for="option in RESULTS" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <section class="et-surface-raised">
            <div v-if="loading" class="text-center py-5">
                <div class="spinner-border" role="status"></div>
            </div>

            <EmptyState
                v-else-if="!scans.length"
                icon="bi-clock-history"
                title="Todavia no hay escaneos"
            />

            <div v-else class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Resultado</th>
                            <th>Evento</th>
                            <th>Entrada</th>
                            <th>Operador</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="scan in scans" :key="scan.id">
                            <td class="numeric small">{{ formatDate(scan.scanned_at) }}</td>
                            <td>
                                <span class="et-badge" :class="`et-badge--${toneOf(scan.result)}`">
                                    {{ labelOf(scan.result) }}
                                </span>
                            </td>
                            <td class="small">{{ scan.event_name || '—' }}</td>
                            <td class="small">
                                <span v-if="scan.ticket_type_name">{{ scan.ticket_type_name }}</span>
                                <span v-else class="text-faint">{{ scan.notes || 'sin boleto' }}</span>
                            </td>
                            <td class="small">{{ scan.scanner_name || '—' }}</td>
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
                    <i class="bi bi-chevron-left"></i>
                </button>

                <span class="small text-muted-2 numeric">
                    {{ meta.current_page }} / {{ meta.last_page }}
                </span>

                <button
                    class="btn btn-et-ghost btn-sm"
                    :disabled="meta.current_page >= meta.last_page"
                    @click="goToPage(meta.current_page + 1)"
                >
                    <i class="bi bi-chevron-right"></i>
                </button>
            </nav>
        </section>
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
    padding: 0.75rem;
    border-top: 1px solid var(--et-border);
}
</style>
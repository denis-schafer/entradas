<script setup>
/**
 * Estadisticas.
 *
 * Sirven para decidir, no para mirar: cuanto se facturo, que eventos mueven la
 * venta y que tipo de entrada conviene abrir o cerrar. El resumen acepta filtro
 * por evento; la exportacion CSV usa el mismo filtro para que lo que se descarga
 * sea lo que se esta viendo.
 */
import { ref, computed, onMounted } from 'vue';
import { Bar } from 'vue-chartjs';
import {
    Chart as ChartJS,
    BarElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
} from 'chart.js';
import api, { toError } from '../api.js';
import StatCard from '../ui/StatCard.vue';
import EmptyState from '../ui/EmptyState.vue';

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend);

const data = ref(null);
const events = ref([]);
const eventId = ref('');
const loading = ref(true);
const error = ref('');

function money(value) {
    return new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        maximumFractionDigits: 0,
    }).format(Number(value || 0));
}

function formatDay(value) {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

async function load() {
    try {
        const { data: payload } = await api.get('tickets-admin/statistics', {
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
        // El filtro es una comodidad; las estadisticas globales se ven igual.
    }
}

function applyEventFilter() {
    loading.value = true;
    load();
}

function exportCsv() {
    // Descarga directa: es la unica forma de respects el Content-Disposition
    // del streaming sin traer el CSV entero a memoria en el navegador.
    const query = eventId.value ? `?event_id=${encodeURIComponent(eventId.value)}` : '';

    window.location.href = `/tickets-admin/statistics/export${query}`;
}

const byEvent = computed(() => data.value?.by_event || []);
const byType = computed(() => data.value?.by_type || []);

const chartData = computed(() => ({
    labels: byEvent.value.map((row) => row.name),
    datasets: [
        {
            label: 'Facturado',
            data: byEvent.value.map((row) => Number(row.revenue || 0)),
            backgroundColor: 'rgba(124, 92, 255, 0.65)',
            borderRadius: 4,
            maxBarThickness: 34,
        },
    ],
}));

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (item) => money(item.parsed.y),
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: {
                color: 'rgba(140, 140, 160, 0.9)',
                maxRotation: 45,
                minRotation: 0,
                autoSkip: false,
                font: { size: 11 },
            },
        },
        y: {
            beginAtZero: true,
            grid: { color: 'rgba(140, 140, 160, 0.15)' },
            ticks: {
                color: 'rgba(140, 140, 160, 0.9)',
                font: { size: 11 },
                callback: (value) => `$${Math.round(value / 1000)}k`,
            },
        },
    },
};

const maxTypeSold = computed(() => Math.max(1, ...byType.value.map((row) => Number(row.sold_count || 0))));

function soldPercent(row) {
    return Math.round((Number(row.sold_count || 0) / maxTypeSold.value) * 100);
}

function sellThrough(row) {
    const stock = Number(row.stock || 0);
    const sold = Number(row.sold_count || 0);

    if (!stock) {
        return null;
    }

    return Math.round((sold / stock) * 100);
}

onMounted(async () => {
    await Promise.all([load(), loadEvents()]);
});
</script>

<template>
    <div>
        <header class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
            <div>
                <h2 class="h5 fw-bold mb-0">Estadisticas</h2>
                <p class="text-muted-2 small mb-0">
                    {{ eventId ? events.find((event) => event.id === eventId)?.name : 'Todos los eventos' }}
                </p>
            </div>

            <div class="d-flex gap-2 align-items-center flex-wrap">
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

                <button class="btn btn-et-ghost btn-sm" @click="exportCsv">
                    <i class="bi bi-filetype-csv me-1"></i>Exportar CSV
                </button>
            </div>
        </header>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <template v-else-if="data">
            <div class="row g-2 mb-3">
                <div class="col-6 col-xl-3">
                    <StatCard label="Facturado" :value="money(data.revenue)" icon="bi-cash-stack" />
                </div>
                <div class="col-6 col-xl-3">
                    <StatCard
                        label="Ordenes pagadas"
                        :value="`${data.orders.paid}`"
                        :hint="`${data.orders.pending} pendientes`"
                        icon="bi-check2-circle"
                    />
                </div>
                <div class="col-6 col-xl-3">
                    <StatCard
                        label="Entradas usadas"
                        :value="`${data.tickets.used}`"
                        :hint="`${data.tickets.valid} disponibles`"
                        icon="bi-ticket-perforated"
                    />
                </div>
                <div class="col-6 col-xl-3">
                    <StatCard label="Escaneos" :value="`${data.scans}`" icon="bi-qr-code-scan" />
                </div>
            </div>

            <div class="row g-2">
                <div class="col-12 col-xl-7">
                    <section class="et-surface-raised p-3 h-100">
                        <h3 class="section__title">Facturado por evento</h3>

                        <div v-if="!byEvent.length" class="text-center py-4">
                            <p class="text-muted-2 small mb-0">Sin ventas para mostrar.</p>
                        </div>

                        <div v-else class="chart-box">
                            <Bar :data="chartData" :options="chartOptions" />
                        </div>
                    </section>
                </div>

                <div class="col-12 col-xl-5">
                    <section class="et-surface-raised p-3 h-100">
                        <h3 class="section__title">Venta por tipo de entrada</h3>

                        <EmptyState
                            v-if="!byType.length"
                            icon="bi-bar-chart"
                            title="Sin datos"
                            hint="Crea tipos de entrada en un evento para ver la venta."
                        />

                        <ul v-else class="type-list">
                            <li v-for="row in byType" :key="row.id" class="type-list__item">
                                <div class="d-flex align-items-baseline justify-content-between gap-2">
                                    <div class="text-truncate">
                                        <span class="fw-semibold">{{ row.name }}</span>
                                        <span class="small text-faint"> · {{ row.event_name }}</span>
                                    </div>
                                    <span class="small numeric fw-semibold">{{ money(row.revenue) }}</span>
                                </div>

                                <div class="bar mt-1">
                                    <div class="bar__fill" :style="{ width: `${soldPercent(row)}%` }"></div>
                                </div>

                                <div class="d-flex align-items-center justify-content-between small text-faint numeric mt-1">
                                    <span>
                                        {{ row.sold_count }} vendidas
                                        <template v-if="sellThrough(row) !== null">
                                            · {{ sellThrough(row) }}% del stock
                                        </template>
                                    </span>
                                    <span>{{ money(row.price) }} c/u</span>
                                </div>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.chart-box {
    position: relative;
    height: 260px;
}

.section__title {
    font-size: 0.95rem;
    font-weight: 700;
    margin: 0 0 0.75rem;
}

.type-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.type-list__item {
    padding: 0.6rem 0;
    border-bottom: 1px solid var(--et-border);
}

.type-list__item:last-child {
    border-bottom: 0;
}

.bar {
    height: 6px;
    border-radius: var(--et-radius-pill);
    background: var(--et-surface-hover);
    overflow: hidden;
}

.bar__fill {
    height: 100%;
    border-radius: var(--et-radius-pill);
    background: var(--et-primary);
}
</style>
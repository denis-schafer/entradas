<script setup>
/**
 * Listado de sorteos del panel.
 *
 * Cada sorteo pertenece a un evento. La lista muestra cuantos premios tiene y
 * cuantos ya se sortearon, para ver de un vistazo que falta. El alta y toda la
 * configuracion viven en RaffleEditView; aca solo se navega.
 */
import { ref, onMounted } from 'vue';
import api, { toError } from '../api.js';
import EmptyState from '../ui/EmptyState.vue';

const emit = defineEmits(['navigate']);

const raffles = ref([]);
const loading = ref(true);
const error = ref('');

const STATUS_LABELS = {
    draft: 'Borrador',
    open: 'Abierto',
    finished: 'Finalizado',
};

async function load() {
    loading.value = raffles.value.length === 0;

    try {
        const { data } = await api.get('tickets-admin/raffles');

        raffles.value = data.data || [];
        error.value = '';
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

function formatDate(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleString('es-AR', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

onMounted(load);
</script>

<template>
    <div>
        <header class="mb-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div>
                <h2 class="h5 fw-bold mb-0">Sorteos</h2>
                <p class="text-muted-2 small mb-0">{{ raffles.length }} sorteos</p>
            </div>

            <button class="btn btn-et-primary" @click="emit('navigate', 'raffle-edit', {})">
                <i class="bi bi-plus-lg me-1"></i>Nuevo sorteo
            </button>
        </header>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <EmptyState
            v-else-if="!raffles.length"
            icon="bi-gift"
            title="No hay sorteos"
            hint="Crea el primero para sortear premios entre las entradas de un evento."
        >
            <button class="btn btn-et-primary btn-sm" @click="emit('navigate', 'raffle-edit', {})">
                Crear sorteo
            </button>
        </EmptyState>

        <div v-else class="d-flex flex-column gap-2">
            <article v-for="raffle in raffles" :key="raffle.id" class="raffle et-surface">
                <div class="raffle__icon">
                    <i class="bi bi-gift"></i>
                </div>

                <div class="raffle__body">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h3 class="mb-0 fw-bold">{{ raffle.name }}</h3>
                        <span class="et-badge" :class="raffle.status === 'open' ? 'et-badge--success' : 'et-badge--muted'">
                            {{ STATUS_LABELS[raffle.status] || raffle.status }}
                        </span>
                    </div>

                    <p class="small text-muted-2 mb-0">
                        <i class="bi bi-calendar-event me-1"></i>{{ raffle.event_name }}
                    </p>

                    <p class="small text-faint mb-0 numeric">
                        {{ raffle.drawn_count }} / {{ raffle.prizes_count }} premios sorteados
                    </p>
                </div>

                <div class="raffle__actions">
                    <button
                        class="btn btn-et-ghost btn-sm"
                        @click="emit('navigate', 'raffle-edit', { id: raffle.id })"
                    >
                        <i class="bi bi-sliders me-1"></i>Abrir
                    </button>
                </div>
            </article>
        </div>
    </div>
</template>

<style scoped>
.raffle {
    display: grid;
    grid-template-columns: 48px minmax(0, 1fr);
    gap: 0.9rem;
    align-items: center;
    padding: 0.75rem 1rem;
}

.raffle__icon {
    width: 48px;
    height: 48px;
    display: grid;
    place-items: center;
    border-radius: var(--et-radius-sm);
    background: var(--et-primary-soft);
    color: var(--et-primary);
    font-size: 1.25rem;
}

.raffle__body {
    min-width: 0;
}

.raffle__actions {
    grid-column: 1 / -1;
    display: flex;
    gap: 0.4rem;
    justify-content: flex-end;
}

@media (min-width: 768px) {
    .raffle {
        grid-template-columns: 48px minmax(0, 1fr) auto;
    }

    .raffle__actions {
        grid-column: auto;
    }
}
</style>

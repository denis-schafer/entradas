<script setup>
/**
 * Listado de eventos del panel.
 *
 * Borrar un evento con historial no borra: el backend lo pasa a "cancelled" y
 * responde 422 con el detalle. La pantalla muestra ese mensaje tal cual, porque
 * es la explicacion de por que el evento sigue en la lista.
 */
import { ref, watch, onMounted } from 'vue';
import api, { toError } from '../api.js';
import StatusBadge from '../ui/StatusBadge.vue';
import EmptyState from '../ui/EmptyState.vue';
import ImageFlyer from '../ui/ImageFlyer.vue';
import { toast } from '../ui/toast.js';

const emit = defineEmits(['navigate']);

const events = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const filters = ref({ search: '', status: '', page: 1 });
const loading = ref(true);
const error = ref('');
const confirming = ref(null);
const busyId = ref(null);

const STATUSES = [
    { value: '', label: 'Todos' },
    { value: 'published', label: 'Publicados' },
    { value: 'draft', label: 'Borradores' },
    { value: 'closed', label: 'Cerrados' },
    { value: 'cancelled', label: 'Cancelados' },
];

async function load() {
    loading.value = events.value.length === 0;

    try {
        const { data } = await api.get('tickets-admin/events', {
            search: filters.value.search || undefined,
            status: filters.value.status || undefined,
            page: filters.value.page,
        });

        events.value = data.data || [];
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

function formatDate(value) {
    if (!value) {
        return 'Sin fecha';
    }

    return new Date(value).toLocaleDateString('es-AR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function askDelete(event) {
    confirming.value = event.id;
}

function doDelete(event) {
    busyId.value = event.id;

    api.delete(`tickets-admin/events/${event.id}`)
        .then(({ data }) => {
            toast(data.message, 'success');

            return load();
        })
        .catch((err) => {
            // 422 aca no es un fallo: significa que el evento tiene historial y
            // quedo archivado en vez de borrado.
            const formatted = toError(err);

            if (formatted.status === 422) {
                toast(formatted.message, 'warning');
            } else {
                toast(formatted.message, 'danger');
            }

            return load();
        })
        .finally(() => {
            busyId.value = null;
            confirming.value = null;
        });
}

function goToPage(page) {
    filters.value.page = page;
    load();
}

let searchTimer = null;

watch(() => filters.value.search, () => {
    // En busqueda se espera a que la persona termine de tipear antes de pegarle al
    // backend en cada tecla.
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        filters.value.page = 1;
        load();
    }, 350);
});

watch(() => filters.value.status, () => {
    filters.value.page = 1;
    load();
});

onMounted(load);
</script>

<template>
    <div>
        <header class="mb-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div>
                <h2 class="h5 fw-bold mb-0">Eventos</h2>
                <p class="text-muted-2 small mb-0">{{ meta.total }} eventos</p>
            </div>

            <button class="btn btn-et-primary" @click="emit('navigate', 'event-edit', {})">
                <i class="bi bi-plus-lg me-1"></i>Nuevo evento
            </button>
        </header>

        <div class="et-surface-raised p-3 mb-3">
            <div class="row g-2">
                <div class="col-12 col-md-8">
                    <label class="form-label" for="search">Buscar</label>
                    <input
                        id="search"
                        v-model="filters.search"
                        type="search"
                        class="form-control"
                        placeholder="Nombre, slug o lugar"
                    >
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label" for="status">Estado</label>
                    <select id="status" v-model="filters.status" class="form-select">
                        <option v-for="option in STATUSES" :key="option.value" :value="option.value">
                            {{ option.label }}
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
            v-else-if="!events.length"
            icon="bi-calendar-x"
            title="No hay eventos"
            hint="Crea el primero para empezar a vender entradas."
        >
            <button class="btn btn-et-primary btn-sm" @click="emit('navigate', 'event-edit', {})">
                Crear evento
            </button>
        </EmptyState>

        <div v-else class="d-flex flex-column gap-2">
            <article v-for="event in events" :key="event.id" class="event et-surface">
                <ImageFlyer
                    v-if="event.cover_image"
                    :src="event.cover_image"
                    :alt="`Portada: ${event.name}`"
                    thumb-class="event__img"
                />
                <div v-else class="event__img event__img--blank">
                    <i class="bi bi-calendar-event"></i>
                </div>

                <div class="event__body">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h3 class="mb-0 fw-bold">{{ event.name }}</h3>
                        <StatusBadge :status="event.status" />
                    </div>

                    <p class="small text-faint mb-1 numeric">
                        {{ formatDate(event.starts_at) }}
                        <span v-if="event.location"> · {{ event.location }}</span>
                    </p>

                    <p class="small text-muted-2 mb-0">
                        <span class="numeric">/{{ event.slug }}</span>
                        <span v-if="event.capacity"> · capacidad {{ event.capacity }}</span>
                    </p>
                </div>

                <div class="event__actions">
                    <button
                        class="btn btn-et-ghost btn-sm"
                        @click="emit('navigate', 'event-edit', { id: event.id })"
                    >
                        <i class="bi bi-pencil me-1"></i>Editar
                    </button>

                    <template v-if="confirming !== event.id">
                        <button class="btn btn-et-ghost btn-sm btn-danger-soft" @click="askDelete(event)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </template>

                    <template v-else>
                        <button
                            class="btn btn-et-primary btn-sm"
                            :disabled="busyId === event.id"
                            @click="doDelete(event)"
                        >
                            <span v-if="busyId === event.id" class="spinner-border spinner-border-sm"></span>
                            <template v-else>Confirmar</template>
                        </button>

                        <button class="btn btn-link btn-sm" @click="confirming = null">No</button>
                    </template>
                </div>
            </article>
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
.event {
    display: grid;
    grid-template-columns: 96px minmax(0, 1fr);
    gap: 0.9rem;
    align-items: center;
    padding: 0.75rem 1rem;
}

@media (max-width: 767.98px) {
    .event {
        grid-template-columns: 72px minmax(0, 1fr);
    }
}

/*
| :deep() para que las clases thumb-class del ImageFlyer matcheen desde aca
| (el button raiz del ImageFlyer solo lleva su propio data-v, no el del
| componente padre, asi que las reglas scoped normales no aplican).
*/
.event :deep(.event__img) {
    width: 96px;
    height: 62px;
    border-radius: var(--et-radius-sm);
}

.event__img--blank {
    display: grid;
    place-items: center;
    color: var(--et-text-faint);
    background: var(--et-surface-hover);
}

@media (max-width: 767.98px) {
    .event__img {
        width: 72px;
        height: 52px;
    }
}

.event__body {
    min-width: 0;
}

.event__actions {
    grid-column: 1 / -1;
    display: flex;
    gap: 0.4rem;
    justify-content: flex-end;
}

@media (min-width: 768px) {
    .event {
        grid-template-columns: 96px minmax(0, 1fr) auto;
    }

    .event__actions {
        grid-column: auto;
    }
}

.pager {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 1rem;
}
</style>
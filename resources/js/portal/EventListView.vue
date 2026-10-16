<script setup>
/**
 * Home del portal: los eventos publicados.
 *
 * Suscripcion a tickets.events (publico) para que una entrada que se agote o
 * un evento que se cierre se note sin recargar. El evento no trae datos: solo
 * dice que algo cambio y esta vista vuelve a pedir la lista.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { listen, startPolling, stopPolling } from '../realtime.js';
import EmptyState from '../ui/EmptyState.vue';
import ImageFlyer from '../ui/ImageFlyer.vue';
import AdSlot from './AdSlot.vue';

defineProps({
    user: { type: Object, default: null },
    config: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['navigate']);

const events = ref([]);
const loading = ref(true);
const error = ref('');
const flashIds = ref([]);

let unsubscribeStock = null;
let unsubscribeEvent = null;

async function load() {
    try {
        const { data } = await api.get('tickets-portal/api/events');

        events.value = data;
        error.value = '';
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

/**
 * Cuando llega un evento se vuelve a pedir la lista y se marcan las tarjetas
 * que cambiaron, para que el cambio se vea y no solo se note en los numeros.
 */
async function refreshAndFlash(event) {
    const before = new Map(events.value.map((e) => [e.id, JSON.stringify(e)]));
    const eventId = event?.eventId ?? event?.event_id;

    await load();

    const changed = events.value
        .filter((e) => !before.has(e.id) || before.get(e.id) !== JSON.stringify(e))
        .map((e) => e.id);

    if (!changed.length && eventId) {
        changed.push(Number(eventId));
    }

    flash(changed);
}

function flash(ids) {
    if (!ids || !ids.length) {
        return;
    }

    flashIds.value = ids;

    setTimeout(() => {
        flashIds.value = [];
    }, 1800);
}

function formatDate(value) {
    if (!value) {
        return 'Fecha a confirmar';
    }

    return new Date(value).toLocaleDateString('es-AR', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

function formatTime(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
}

onMounted(async () => {
    await load();

    // Los nombres de evento del backend son Broadcast::as(); stock.changed es
    // el de los tipos de entrada y event.status el del evento.
    unsubscribeStock = listen('tickets.events', 'stock.changed', refreshAndFlash);
    unsubscribeEvent = listen('tickets.events', 'event.status', refreshAndFlash);

    // Respaldo: si el websocket no esta, la lista se actualiza sola igual.
    startPolling(load);
});

onBeforeUnmount(() => {
    unsubscribeStock?.();
    unsubscribeEvent?.();
    stopPolling();
});
</script>

<template>
    <div class="events">
        <header class="events__head">
            <div>
                <h1 class="h3 fw-bold mb-1">Proximos eventos</h1>
                <p class="text-muted-2 mb-0">Elegi tus entradas y listo.</p>
            </div>
        </header>

        <AdSlot position="banner" class="mb-4" />

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <EmptyState
            v-else-if="!events.length"
            icon="bi-calendar-x"
            title="Todavia no hay eventos publicados"
            hint="Cuando se publiquen, los vas a ver aca."
        />

        <div v-else class="events__list">
            <article
                v-for="event in events"
                :key="event.id"
                class="event-row et-surface"
                :class="{ 'is-flashing': flashIds.includes(event.id) }"
                @click="emit('navigate', 'event', { slug: event.slug })"
            >
                <div v-if="event.cover_image" class="event-row__cover" @click.stop>
                    <ImageFlyer
                        :src="event.cover_image"
                        :alt="`Portada: ${event.name}`"
                        thumb-class="event-row__img"
                    />
                </div>
                <div v-else class="event-row__blank">
                    <i class="bi bi-ticket-perforated"></i>
                </div>

                <div class="event-row__body">
                    <h2 class="h6 fw-bold mb-1">{{ event.name }}</h2>

                    <p class="text-muted-2 small mb-2">
                        <i class="bi bi-calendar-event me-1"></i>
                        {{ formatDate(event.starts_at) }}
                        <span v-if="formatTime(event.starts_at)" class="numeric">
                            {{ formatTime(event.starts_at) }}
                        </span>
                    </p>

                    <p v-if="event.location" class="text-muted-2 small mb-0">
                        <i class="bi bi-geo-alt me-1"></i>{{ event.location }}
                    </p>
                </div>

                <div class="event-row__go">
                    Ver entradas
                    <i class="bi bi-arrow-right"></i>
                </div>
            </article>
        </div>

        <AdSlot position="top" class="mt-4" />
    </div>
</template>

<style scoped>
.events__head {
    margin-bottom: 1.5rem;
}

.events__list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

/*
| Cada evento ocupa toda la fila: el flyer adelante en miniatura (con lightbox
| al hacerle click) y el resto de la fila abre el evento. El @click.stop del
| contenedor del flyer evita que ampliar la imagen tambien navegue.
*/
.event-row {
    display: grid;
    grid-template-columns: 96px minmax(0, 1fr) auto;
    gap: 1rem;
    align-items: center;
    padding: 0.75rem 1rem;
    cursor: pointer;
    transition: transform var(--et-transition), border-color var(--et-transition);
}

.event-row:hover {
    transform: translateY(-2px);
    border-color: var(--et-border-strong);
}

.event-row__cover,
.event-row__blank {
    width: 96px;
    height: 96px;
    border-radius: var(--et-radius-sm);
}

/*
| :deep() porque la clase thumb-class cae en el root del ImageFlyer (otro
| componente con su propio scope), no en este.
*/
.event-row :deep(.event-row__img) {
    width: 96px;
    height: 96px;
    border-radius: var(--et-radius-sm);
}

.event-row__cover {
    overflow: hidden;
}

.event-row__blank {
    display: grid;
    place-items: center;
    font-size: 1.6rem;
    color: var(--et-text-faint);
    background: linear-gradient(135deg, var(--et-primary-soft), transparent);
}

.event-row__body {
    min-width: 0;
}

.event-row__go {
    color: var(--et-primary);
    font-weight: 600;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    white-space: nowrap;
}

@media (max-width: 575.98px) {
    .event-row {
        grid-template-columns: 72px minmax(0, 1fr);
        gap: 0.75rem;
    }

    .event-row__cover,
    .event-row__blank {
        width: 72px;
        height: 72px;
    }

    .event-row :deep(.event-row__img) {
        width: 72px;
        height: 72px;
    }

    .event-row__go {
        grid-column: 1 / -1;
        justify-content: flex-end;
    }
}
</style>
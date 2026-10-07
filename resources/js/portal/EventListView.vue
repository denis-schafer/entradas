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

        <div v-else class="row g-3">
            <div v-for="event in events" :key="event.id" class="col-12 col-md-6 col-lg-4">
                <article
                    class="event-card et-surface"
                    :class="{ 'is-flashing': flashIds.includes(event.id) }"
                    @click="emit('navigate', 'event', { slug: event.slug })"
                >
                    <div class="event-card__cover">
                        <img v-if="event.cover_image" :src="event.cover_image" :alt="event.name" loading="lazy">
                        <div v-else class="event-card__cover-fallback">
                            <i class="bi bi-ticket-perforated"></i>
                        </div>
                    </div>

                    <div class="event-card__body">
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

                    <div class="event-card__go">
                        Ver entradas
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </article>
            </div>
        </div>

        <AdSlot position="top" class="mt-4" />
    </div>
</template>

<style scoped>
.events__head {
    margin-bottom: 1.5rem;
}

.event-card {
    height: 100%;
    overflow: hidden;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    transition: transform var(--et-transition), border-color var(--et-transition);
}

.event-card:hover {
    transform: translateY(-3px);
    border-color: var(--et-border-strong);
}

.event-card__cover {
    aspect-ratio: 16 / 9;
    overflow: hidden;
    background: var(--et-surface-hover);
}

.event-card__cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform var(--et-transition-slow);
}

.event-card:hover .event-card__cover img {
    transform: scale(1.04);
}

.event-card__cover-fallback {
    height: 100%;
    display: grid;
    place-items: center;
    font-size: 2rem;
    color: var(--et-text-faint);
    background: linear-gradient(135deg, var(--et-primary-soft), transparent);
}

.event-card__body {
    padding: 1rem 1.1rem 0.75rem;
    flex: 1;
}

.event-card__go {
    padding: 0.75rem 1.1rem 1rem;
    color: var(--et-primary);
    font-weight: 600;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
</style>
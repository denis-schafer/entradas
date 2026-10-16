<script setup>
/**
 * Ventana publica de proyeccion de un sorteo.
 *
 * No tiene sesion: se entra con el token del sorteo en la URL (/sorteo/{token}).
 * Muestra todos los premios y, cuando el operador sortea uno, lo revela con una
 * animacion grande para proyectar.
 *
 * Mismo criterio que el resto de la app: el websocket solo avisa. Al recibir el
 * evento se vuelve a pedir el estado publico al backend y se reemplaza lo que
 * hay. Si el websocket se cae, el polling lo cubre igual.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import api from '../api.js';
import { listen, startPolling, stopPolling, realtimeState } from '../realtime.js';

const props = defineProps({
    token: { type: String, required: true },
});

const state = ref(null);
const error = ref('');
const reveal = ref(null);
const highlight = ref(null);

let unsubscribe = null;
let revealTimer = null;
let highlightTimer = null;

/* Posicion -> numero ya visto, para detectar cual premio se acaba de sortear. */
let seenWinners = {};

const raffleName = computed(() => state.value?.raffle?.name || 'Sorteo');
const eventName = computed(() => state.value?.event?.name || '');
const prizes = computed(() => state.value?.prizes || []);

function isDrawn(prize) {
    return prize.winner_ticket_id != null;
}

function displayNumber(prize) {
    return prize.ticket_number != null ? String(prize.ticket_number).padStart(4, '0') : '—';
}

function showReveal(prize) {
    reveal.value = {
        name: prize.name || `Premio ${prize.position}`,
        number: displayNumber(prize),
        winner: prize.winner_name || 'Sin nombre',
    };
    highlight.value = prize.position;

    clearTimeout(revealTimer);
    clearTimeout(highlightTimer);

    revealTimer = setTimeout(() => {
        reveal.value = null;
    }, 7000);

    highlightTimer = setTimeout(() => {
        highlight.value = null;
    }, 9000);
}

async function load() {
    try {
        const { data } = await api.get(`tickets-portal/api/public/raffle/${props.token}`);

        const list = data.prizes || [];
        let justDrawn = null;

        for (const prize of list) {
            const key = prize.position;

            if (isDrawn(prize) && seenWinners[key] !== prize.ticket_number) {
                justDrawn = prize;
            }

            seenWinners[key] = isDrawn(prize) ? prize.ticket_number : null;
        }

        state.value = { ...data, prizes: list };
        error.value = '';

        if (justDrawn) {
            showReveal(justDrawn);
        }
    } catch (err) {
        if (!state.value) {
            error.value = 'No se encontro el sorteo';
        }
    }
}

onMounted(() => {
    document.documentElement.setAttribute('data-bs-theme', 'dark');

    load();

    unsubscribe = listen(`raffle.${props.token}`, 'raffle.prize.drawn', () => load());

    startPolling(load, 8000);
});

onBeforeUnmount(() => {
    if (unsubscribe) {
        unsubscribe();
    }

    clearTimeout(revealTimer);
    clearTimeout(highlightTimer);

    stopPolling();
});
</script>

<template>
    <div class="proj">
        <header class="proj__head">
            <div>
                <p v-if="eventName" class="proj__event">{{ eventName }}</p>
                <h1 class="proj__title">{{ raffleName }}</h1>
            </div>

            <span class="proj__live" :class="{ 'is-on': realtimeState.connected }">
                <i class="bi" :class="realtimeState.connected ? 'bi-broadcast' : 'bi-wifi-off'"></i>
                {{ realtimeState.connected ? 'En vivo' : 'Actualizando' }}
            </span>
        </header>

        <main class="proj__body">
            <div v-if="error" class="proj__error">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ error }}
            </div>

            <div v-else-if="!state" class="proj__loading">
                <div class="spinner-border text-light" role="status"></div>
            </div>

            <div v-else class="proj__grid">
                <article
                    v-for="prize in prizes"
                    :key="prize.id"
                    class="pcard"
                    :class="{ 'is-drawn': isDrawn(prize), 'is-highlight': highlight === prize.position }"
                >
                    <span class="pcard__pos">Premio {{ prize.position }}</span>
                    <h2 class="pcard__name">{{ prize.name || `Premio ${prize.position}` }}</h2>
                    <p v-if="prize.description" class="pcard__desc">{{ prize.description }}</p>

                    <div v-if="isDrawn(prize)" class="pcard__result">
                        <div class="pcard__number numeric">{{ displayNumber(prize) }}</div>
                        <div class="pcard__winner">{{ prize.winner_name || 'Sin nombre' }}</div>
                    </div>
                    <div v-else class="pcard__pending">
                        <i class="bi bi-hourglass-split me-2"></i>Pendiente
                    </div>
                </article>
            </div>
        </main>

        <transition name="reveal">
            <div v-if="reveal" class="reveal">
                <div class="reveal__card">
                    <p class="reveal__prize">{{ reveal.name }}</p>
                    <div class="reveal__number numeric">{{ reveal.number }}</div>
                    <p class="reveal__winner"><i class="bi bi-trophy-fill me-2"></i>{{ reveal.winner }}</p>
                </div>
            </div>
        </transition>
    </div>
</template>

<style scoped>
.proj {
    min-height: 100vh;
    background: radial-gradient(circle at 50% 0%, #1b1d2b 0%, #0a0b12 60%);
    color: #fff;
    display: flex;
    flex-direction: column;
}

.proj__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.5rem 2rem;
}

.proj__event {
    margin: 0;
    color: #9aa0b4;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-size: 0.85rem;
}

.proj__title {
    margin: 0;
    font-size: clamp(1.5rem, 3vw, 2.5rem);
    font-weight: 800;
}

.proj__live {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.85rem;
    border-radius: 999px;
    background: rgb(255 255 255 / 6%);
    color: #9aa0b4;
    font-size: 0.85rem;
    white-space: nowrap;
}

.proj__live.is-on {
    color: #34d399;
}

.proj__body {
    flex: 1;
    padding: 1rem 2rem 2.5rem;
}

.proj__loading,
.proj__error {
    display: grid;
    place-items: center;
    min-height: 50vh;
    font-size: 1.25rem;
    color: #9aa0b4;
    text-align: center;
}

.proj__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.25rem;
}

.pcard {
    background: rgb(255 255 255 / 4%);
    border: 1px solid rgb(255 255 255 / 8%);
    border-radius: 18px;
    padding: 1.5rem;
    transition: border-color 0.3s ease, box-shadow 0.3s ease, transform 0.3s ease;
}

.pcard.is-drawn {
    background: rgb(250 204 21 / 8%);
    border-color: rgb(250 204 21 / 35%);
}

.pcard.is-highlight {
    transform: scale(1.03);
    box-shadow: 0 0 0 3px rgb(250 204 21 / 55%), 0 18px 50px rgb(0 0 0 / 45%);
}

.pcard__pos {
    display: inline-block;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #9aa0b4;
}

.pcard__name {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0.35rem 0 0.25rem;
}

.pcard__desc {
    color: #b9becd;
    margin-bottom: 0.75rem;
}

.pcard__result {
    margin-top: 1rem;
}

.pcard__number {
    font-size: clamp(2.5rem, 6vw, 4rem);
    font-weight: 800;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    color: #facc15;
}

.pcard__winner {
    margin-top: 0.4rem;
    font-size: 1.15rem;
    font-weight: 600;
}

.pcard__pending {
    margin-top: 1rem;
    color: #6b7188;
    font-size: 1.1rem;
}

.reveal {
    position: fixed;
    inset: 0;
    z-index: 2000;
    display: grid;
    place-items: center;
    background: rgb(0 0 0 / 82%);
    backdrop-filter: blur(6px);
}

.reveal__card {
    text-align: center;
    animation: pop 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.reveal__prize {
    font-size: 1.4rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #9aa0b4;
    margin-bottom: 0.5rem;
}

.reveal__number {
    font-size: clamp(6rem, 22vw, 16rem);
    font-weight: 900;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    color: #facc15;
    text-shadow: 0 10px 60px rgb(250 204 21 / 45%);
}

.reveal__winner {
    margin-top: 1rem;
    font-size: clamp(1.5rem, 4vw, 3rem);
    font-weight: 700;
}

.reveal-enter-active,
.reveal-leave-active {
    transition: opacity 0.3s ease;
}

.reveal-enter-from,
.reveal-leave-to {
    opacity: 0;
}

@keyframes pop {
    from {
        transform: scale(0.85);
        opacity: 0;
    }

    to {
        transform: scale(1);
        opacity: 1;
    }
}
</style>

<script setup>
/**
 * Escaner de entradas.
 *
 * Es la pantalla que se usa en la puerta del evento, asi que esta pensada para
 * una tablet con el celu en una mano y con la luz de encima:
 *
 *   - El video ocupa todo lo posible y el resultado se lee a distancia.
 *   - El color es la senal principal: verde valido, amarillo ya usada, rojo
 *     rechazada. El texto acompaña, no al revés.
 *   - Despues de cada lectura queda un bloqueo corto para que la misma
 *     entrada no se lea dos veces seguidas por error.
 *
 * El endpoint /tickets-admin/scans es el que decide. Aca solo se muestra lo que
 * respondio: si dos Administradores escanean la misma entrada al mismo tiempo,
 * el que pierda recibe "ya utilizada" y eso es correcto.
 */
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { BrowserMultiFormatReader } from '@zxing/browser';
import { BarcodeFormat, DecodeHintType, NotFoundException } from '@zxing/library';
import api, { toError } from '../api.js';
import { listen, startPolling, stopPolling } from '../realtime.js';
import EmptyState from '../ui/EmptyState.vue';

const props = defineProps({
    event_id: { type: [Number, String], default: null },
});

const emit = defineEmits(['navigate']);

const events = ref([]);
const selectedEvent = ref(props.event_id ? Number(props.event_id) : null);
const videoEl = ref(null);
const scanning = ref(false);
const cameraError = ref('');
const manual = ref('');
const result = ref(null);
const history = ref([]);
const busy = ref(false);

/* El bloqueo post-lectura. Sin esto, el mismo QR se redecodifica en el siguiente
   frame y el operador ve "valida" flashing aunque la entrada ya se quemo. */
let locked = false;
let reader = null;
let controls = null;
let cooldown = null;
let unsubscribeEvent = null;

const LOOKUP = {
    valid: {
        tone: 'success',
        title: 'Entrada valida',
        icon: 'bi-check-circle-fill',
    },
    used: {
        tone: 'warning',
        title: 'Ya fue utilizada',
        icon: 'bi-exclamation-triangle-fill',
    },
    wrong_event: {
        tone: 'danger',
        title: 'De otro evento',
        icon: 'bi-x-octagon-fill',
    },
    invalid: {
        tone: 'danger',
        title: 'Entrada invalida',
        icon: 'bi-x-circle-fill',
    },
};

const view = computed(() => LOOKUP[result.value?.result] || LOOKUP.invalid);

const canScan = computed(() => selectedEvent.value && !busy.value);

async function loadEvents() {
    try {
        const { data } = await api.get('tickets-admin/events', {
            all: 1,
            status: 'published',
        });

        events.value = data;

        // Si no hay evento elegido y hay solo uno publicado, se elige solo: en
        // un evento con un solo evento cargado, elegir cada vez es ruido.
        if (!selectedEvent.value && data.length === 1) {
            selectedEvent.value = data[0].id;
        }
    } catch (err) {
        cameraError.value = toError(err).message;
    }
}

/**
 * ZXing necesita los formatos en un hint aparte para no emissions el modo
 * CODE_39, que en un evento con entradas parece una barra cualquiera y dispara
 * falsos positivos.
 */
function hints() {
    return new Map([
        [DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.QR_CODE]],
        [DecodeHintType.TRY_HARDER, true],
    ]);
}

async function startCamera() {
    if (!selectedEvent.value || scanning.value) {
        return;
    }

    cameraError.value = '';
    result.value = null;

    await nextTick();

    if (!videoEl.value) {
        return;
    }

    try {
        reader = new BrowserMultiFormatReader(hints(), {
            delayBetweenScanAttempts: 200,
        });

        controls = await reader.decodeFromConstraints(
            { video: { facingMode: { ideal: 'environment' } }, audio: false },
            videoEl.value,
            (decoded, err) => {
                // NotFoundException es lo NORMAL: el fajo de frames donde no
                // hay ningun QR. Solo interesan los errores reales.
                if (err && !(err instanceof NotFoundException)) {
                    return;
                }

                if (decoded && !locked) {
                    handlePayload(decoded.getText());
                }
            }
        );

        scanning.value = true;
    } catch (err) {
        cameraError.value = cameraMessage(err);
    }
}

function cameraMessage(err) {
    const name = err?.name || '';
    const isInsecure = typeof window !== 'undefined'
        && window.isSecureContext === false;

    /*
    | iOS (Safari y Chrome-en-iOS) exige HTTPS para getUserMedia. Si la pagina
    | se sirve por HTTP, el navegador bloquea el acceso a la camara con un
    | NotAllowedError generico que no dice nada del problema real. Avisamos
    | el contexto inseguro explicitamente para que el operador sepa como
    | salir del paso sin tener que adivinar.
    */
    if (isInsecure) {
        return 'La pagina no es HTTPS. iOS bloquea la camara en HTTP. '
            + 'Sirve el sitio por HTTPS (ngrok, cloudflared) para poder escanear.';
    }

    if (name === 'NotAllowedError' || name === 'SecurityError') {
        return 'El navegador bloqueo la camara. '
            + 'En iOS: Ajustes > Safari/Chrome > Camara > Permitir. '
            + 'Y tene que estar HTTPS, no solo HTTP.';
    }

    if (name === 'NotFoundError' || name === 'OverconstrainedError') {
        return 'No se encontro ninguna camara en este dispositivo.';
    }

    if (name === 'NotReadableError') {
        return 'La camara esta ocupada por otra aplicacion.';
    }

    return 'No se pudo abrir la camara.';
}

function stopCamera() {
    controls?.stop();
    controls = null;
    reader = null;
    scanning.value = false;

    if (cooldown) {
        clearTimeout(cooldown);
        cooldown = null;
    }

    locked = false;
}

function lock(seconds = 2.5) {
    locked = true;

    cooldown = setTimeout(() => {
        locked = false;
    }, seconds * 1000);
}

async function handlePayload(text) {
    if (!selectedEvent.value || busy.value) {
        return;
    }

    busy.value = true;
    result.value = null;

    try {
        const { data } = await api.post('tickets-admin/scans', {
            qr_payload: text,
            event_id: Number(selectedEvent.value),
        });

        result.value = data;

        history.value = [
            {
                id: Date.now(),
                result: data.result,
                message: data.message,
                ticket: data.ticket,
                event_id: Number(selectedEvent.value),
            },
            ...history.value,
        ].slice(0, 12);

        lock();
    } catch (err) {
        const formatted = toError(err);

        result.value = {
            result: 'invalid',
            message: formatted.message || 'No se pudo validar la entrada',
        };

        lock();
    } finally {
        busy.value = false;
    }
}

function manualSubmit() {
    if (!manual.value.trim() || !selectedEvent.value) {
        return;
    }

    handlePayload(manual.value.trim());
    manual.value = '';
}

/**
 * Cambiar de evento corta la camara: el QR de un evento no debe validarse
 * contra otro, y dejarlo corrido escaneando con el evento anterior guardado
 * seria peor que reiniciar.
 */
watch(selectedEvent, (next, previous) => {
    if (next !== previous) {
        stopCamera();
        result.value = null;
        history.value = [];
    }
});

onMounted(async () => {
    await loadEvents();

    // Los escaneos de otros operadores se ven en la lista lateral, para que dos
    // puertas distintas puedan ver lo que pasa en la otra.
    unsubscribeEvent = listen('tickets.admin', 'ticket.scanned', async () => {
        // El historial local ya tiene el escaneo propio; los ajenos se cargan
        // del backend para no perder el orden real.
        const { data } = await api.get('tickets-admin/scans', {
            event_id: selectedEvent.value,
        });

        history.value = (data.data || []).slice(0, 12).map((s) => ({
            id: s.id,
            result: s.result,
            message: s.notes || (s.result === 'valid' ? 'Entrada valida' : 'Rechazada'),
            ticket: { type_name: s.ticket_type_name },
            event_id: s.event_id,
            scanned_at: s.scanned_at,
            scanner_name: s.scanner_name,
        }));
    });

    startPolling(loadEvents, 60000);
});

onBeforeUnmount(() => {
    stopCamera();
    unsubscribeEvent?.();
    stopPolling();
});
</script>

<template>
    <div class="scanner">
        <div class="scanner__controls et-surface-raised p-3 mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="event">Evento</label>
                    <select id="event" v-model="selectedEvent" class="form-select">
                        <option :value="null" disabled>Elegi un evento</option>
                        <option v-for="event in events" :key="event.id" :value="event.id">
                            {{ event.name }}
                        </option>
                    </select>
                </div>

                <div class="col-12 col-md-6 d-flex gap-2">
                    <button
                        v-if="!scanning"
                        class="btn btn-et-primary flex-grow-1"
                        :disabled="!canScan"
                        @click="startCamera"
                    >
                        <i class="bi bi-camera me-1"></i>Activar camara
                    </button>
                    <button v-else class="btn btn-et-ghost flex-grow-1" @click="stopCamera">
                        <i class="bi bi-stop-circle me-1"></i>Detener
                    </button>
                </div>
            </div>

            <EmptyState
                v-if="!events.length"
                class="mt-3"
                icon="bi-calendar-x"
                title="No hay eventos para escanear"
                hint="Si sos cajero, pedi que te asignen a alguno."
            />

            <p v-if="cameraError" class="small mt-3 mb-2" style="color: var(--et-danger)">
                <i class="bi bi-exclamation-triangle me-1"></i>{{ cameraError }}
            </p>
        </div>

        <div class="scanner__grid">
            <section class="scanner__stage">
                <div class="stage et-surface">
                    <video ref="videoEl" class="stage__video" playsinline muted></video>

                    <div v-if="!scanning" class="stage__placeholder">
                        <i class="bi bi-upc-scan"></i>
                        <p class="mb-0">Activa la camara para empezar a escanear</p>
                    </div>

                    <div class="stage__reticle" :class="{ 'is-live': scanning }"></div>

                    <div
                        v-if="result"
                        class="stage__flash"
                        :class="`stage__flash--${view.tone}`"
                    >
                        <i class="bi" :class="view.icon"></i>
                        <strong>{{ view.title }}</strong>
                        <span v-if="result.ticket" class="small">
                            {{ result.ticket.type_name }} · {{ result.ticket.buyer_name }}
                        </span>
                        <span v-else-if="result.message" class="small">{{ result.message }}</span>
                    </div>
                </div>

                <form class="manual mt-3" @submit.prevent="manualSubmit">
                    <label class="form-label" for="manual">O pegá el contenido del QR</label>
                    <div class="d-flex gap-2">
                        <input
                            id="manual"
                            v-model="manual"
                            type="text"
                            class="form-control numeric"
                            placeholder='{"uuid":"...","s":"..."}'
                        >
                        <button class="btn btn-et-ghost" type="submit" :disabled="!manual.trim() || !selectedEvent">
                            Validar
                        </button>
                    </div>
                </form>
            </section>

            <aside class="scanner__side">
                <section class="et-surface-raised">
                    <header class="side__head">
                        <h2 class="side__title">Ultimos escaneos</h2>
                        <button class="btn btn-link btn-sm p-0" @click="emit('navigate', 'scans')">
                            Ver todos
                        </button>
                    </header>

                    <EmptyState
                        v-if="!history.length"
                        icon="bi-clock-history"
                        title="Aun no escaneaste nada"
                    />

                    <ul v-else class="side__list">
                        <li v-for="item in history" :key="item.id" class="scan">
                            <span class="scan__dot" :class="`scan__dot--${LOOKUP[item.result]?.tone || 'danger'}`"></span>
                            <div class="min-w-0">
                                <div class="fw-semibold text-truncate">
                                    {{ item.ticket?.type_name || item.message }}
                                </div>
                                <div class="small text-faint text-truncate">
                                    <template v-if="item.ticket?.buyer_name">{{ item.ticket.buyer_name }}</template>
                                    <template v-else>{{ item.message }}</template>
                                </div>
                            </div>
                        </li>
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</template>

<style scoped>
.scanner__grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: minmax(0, 1fr);
}

@media (min-width: 992px) {
    .scanner__grid {
        grid-template-columns: minmax(0, 1fr) 340px;
    }
}

.stage {
    position: relative;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    border-radius: var(--et-radius);
    background: #0b0b10;
}

.stage__video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.stage__placeholder {
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    align-content: center;
    gap: 0.75rem;
    color: #6b6b7d;
    font-size: 0.9rem;
    text-align: center;
    padding: 1.5rem;
}

.stage__placeholder i {
    font-size: 2.5rem;
}

.stage__reticle {
    position: absolute;
    inset: 18%;
    border: 2px solid rgb(255 255 255 / 22%);
    border-radius: 18px;
    pointer-events: none;
}

.stage__reticle.is-live {
    border-color: rgb(124 92 255 / 75%);
    box-shadow: 0 0 0 9999px rgb(0 0 0 / 22%);
}

.stage__flash {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-align: center;
    padding: 1.5rem;
    color: #fff;
    font-size: 1.35rem;
}

.stage__flash i {
    font-size: 4.5rem;
    line-height: 1;
}

.stage__flash--success { background: rgb(46 213 115 / 92%); }
.stage__flash--warning { background: rgb(255 193 7 / 92%); color: #2b2100; }
.stage__flash--danger { background: rgb(255 61 113 / 92%); }

.side__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.8rem 1rem;
    border-bottom: 1px solid var(--et-border);
}

.side__title {
    font-size: 0.9rem;
    font-weight: 700;
    margin: 0;
}

.side__list {
    list-style: none;
    margin: 0;
    padding: 0.4rem;
    max-height: 420px;
    overflow-y: auto;
}

.scan {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.5rem 0.6rem;
    border-radius: var(--et-radius-sm);
}

.scan:hover {
    background: var(--et-surface-hover);
}

.scan__dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
}

.scan__dot--success { background: var(--et-success); }
.scan__dot--warning { background: var(--et-warning); }
.scan__dot--danger { background: var(--et-danger); }

.min-w-0 {
    min-width: 0;
}
</style>
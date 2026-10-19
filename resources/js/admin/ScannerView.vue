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
const pending = ref(null);
const validating = ref(false);
const validationError = ref('');

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

// Resultado de la vista previa que se esta mostrando en el modal.
const pendingView = computed(() => LOOKUP[pending.value?.result] || LOOKUP.invalid);

const TICKET_STATUS = {
    valid: 'Valida',
    used: 'Usada',
    cancelled: 'Cancelada',
    expired: 'Vencida',
};

const ORDER_STATUS = {
    paid: 'Pagada',
    pending: 'Pendiente',
    cancelled: 'Cancelada',
    expired: 'Vencida',
};

function ticketStatusLabel(status) {
    return TICKET_STATUS[status] || status || '—';
}

function orderStatusLabel(status) {
    return ORDER_STATUS[status] || status || '—';
}

function initials(name) {
    return String(name || '')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

// El evento en curso, para mostrarlo como nombre cuando hay solo uno.
const currentEvent = computed(() => events.value.find((event) => event.id === selectedEvent.value) || null);

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

/*
| Tocar el area de escaneo prende la camara: en la puerta no se busca un
| boton, se apunta y se toca. Con la camara ya prendida el toque no hace
| nada (detener sigue siendo el boton del encabezado), para que un dedo
| que resbale sobre el video no corte una lectura en curso.
*/
function onStageClick() {
    if (scanning.value || busy.value) {
        return;
    }

    if (!selectedEvent.value) {
        cameraError.value = events.value.length
            ? 'Elegi un evento para escanear.'
            : 'No hay eventos para escanear.';
        return;
    }

    startCamera();
}

function lock(seconds = 2.5) {
    locked = true;

    cooldown = setTimeout(() => {
        locked = false;
    }, seconds * 1000);
}

function addHistory(data) {
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
}

/**
 * Primer paso: pide los datos del comprador SIN consumir la entrada. El
 * backend valida la firma, el evento, el pago y el estado, pero no marca la
 * entrada como usada. La camara queda bloqueada mientras el modal este abierto
 * para que el mismo QR no se lea dos veces.
 */
async function handlePayload(text) {
    if (!selectedEvent.value || busy.value || pending.value) {
        return;
    }

    busy.value = true;
    result.value = null;
    validationError.value = '';

    try {
        const { data } = await api.post('tickets-admin/scans/preview', {
            qr_payload: text,
            event_id: Number(selectedEvent.value),
        });

        pending.value = { ...data, qr_payload: text };
        locked = true;
    } catch (err) {
        const formatted = toError(err);

        result.value = {
            result: 'invalid',
            message: formatted.message || 'No se pudo leer la entrada',
        };

        lock();
    } finally {
        busy.value = false;
    }
}

function cancelPending() {
    if (validating.value) {
        return;
    }

    pending.value = null;
    validationError.value = '';

    // Cooldown para que el mismo QR que sigue frente a la camara no vuelva a
    // abrir el modal de inmediato.
    lock();
}

/**
 * Segundo paso: recien aca se consume la entrada. Si otro operador la valido
 * entre la vista previa y este click, el backend responde "ya utilizada" y se
 * muestra ese resultado.
 */
async function confirmValidation() {
    if (!pending.value || validating.value || !pending.value.can_validate) {
        return;
    }

    validating.value = true;
    validationError.value = '';

    try {
        const { data } = await api.post('tickets-admin/scans', {
            qr_payload: pending.value.qr_payload,
            event_id: Number(selectedEvent.value),
        });

        result.value = data;
        addHistory(data);
        pending.value = null;
        lock();
    } catch (err) {
        const formatted = toError(err);

        validationError.value = formatted.message || 'No se pudo validar la entrada';
    } finally {
        validating.value = false;
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
        pending.value = null;
        validationError.value = '';
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
    pending.value = null;
    unsubscribeEvent?.();
    stopPolling();
});
</script>

<template>
    <div class="scanner">
        <div class="scanner__controls et-surface-raised p-3 mb-3">
            <div class="row g-2 align-items-end">
                <!--
                    Con un solo evento elegir es ruido: se muestra el nombre.
                    El select aparece recien cuando hay mas de uno (el caso de
                    un cajero con varios eventos asignados).
                -->
                <div class="col-12 col-md-6">
                    <label v-if="events.length > 1" class="form-label" for="event">Evento</label>
                    <select
                        v-if="events.length > 1"
                        id="event"
                        v-model="selectedEvent"
                        class="form-select"
                    >
                        <option :value="null" disabled>Elegi un evento</option>
                        <option v-for="event in events" :key="event.id" :value="event.id">
                            {{ event.name }}
                        </option>
                    </select>
                    <div v-else-if="currentEvent">
                        <div class="form-label">Evento</div>
                        <div class="fw-semibold">{{ currentEvent.name }}</div>
                    </div>
                </div>

                <div class="col-12 col-md-6 d-flex gap-2 justify-content-md-end">
                    <button v-if="scanning" class="btn btn-et-ghost" @click="stopCamera">
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
                <div class="stage et-surface" :class="{ 'is-idle': !scanning }" @click="onStageClick">
                    <video ref="videoEl" class="stage__video" playsinline muted></video>

                    <div v-if="!scanning" class="stage__placeholder">
                        <i class="bi bi-upc-scan"></i>
                        <p class="mb-0">Toca el area para activar la camara</p>
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
                    <label class="form-label" for="manual">O pega el uuid o el contenido del QR</label>
                    <div class="d-flex gap-2">
                        <input
                            id="manual"
                            v-model="manual"
                            type="text"
                            class="form-control"
                            placeholder="El uuid impreso debajo del QR"
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

        <!--
            Revision previa: se muestran los datos del comprador y recien al
            tocar "Validar entrada" se consume el QR. Cancelar/cerrar no marca
            la entrada como usada.
        -->
        <div v-if="pending" class="modal-backdrop" @click.self="cancelPending">
            <div class="modal-sheet scan-modal" role="dialog" aria-modal="true" aria-labelledby="scan-modal-title">
                <header class="modal-sheet__head">
                    <div>
                        <span class="scan-modal__badge" :class="`scan-modal__badge--${pendingView.tone}`">
                            <i class="bi" :class="pendingView.icon"></i>
                            {{ pendingView.title }}
                        </span>
                        <h2 id="scan-modal-title" class="scan-modal__title">Revisá los datos del comprador</h2>
                    </div>
                    <button
                        type="button"
                        class="btn btn-link p-0 text-decoration-none"
                        aria-label="Cerrar"
                        @click="cancelPending"
                    >
                        <i class="bi bi-x-lg fs-5"></i>
                    </button>
                </header>

                <div class="modal-sheet__body">
                    <template v-if="pending.ticket">
                        <div class="scan-modal__person">
                            <span class="scan-modal__avatar">{{ initials(pending.ticket.buyer_name) }}</span>
                            <div class="min-w-0">
                                <h3 class="scan-modal__name">{{ pending.ticket.buyer_name || 'Sin nombre' }}</h3>
                                <p class="scan-modal__meta">
                                    {{ pending.ticket.type_name || 'Entrada' }}
                                    <span v-if="pending.ticket.event_name"> · {{ pending.ticket.event_name }}</span>
                                </p>
                            </div>
                        </div>

                        <dl class="scan-modal__data">
                            <div>
                                <dt>DNI</dt>
                                <dd>{{ pending.ticket.buyer_dni || '—' }}</dd>
                            </div>
                            <div>
                                <dt>Email</dt>
                                <dd>{{ pending.ticket.buyer_email || '—' }}</dd>
                            </div>
                            <div>
                                <dt>Teléfono</dt>
                                <dd>{{ pending.ticket.buyer_phone || '—' }}</dd>
                            </div>
                            <div>
                                <dt>Estado</dt>
                                <dd>
                                    {{ ticketStatusLabel(pending.ticket.status) }}
                                    <span v-if="pending.ticket.order_status && pending.ticket.order_status !== 'paid'">
                                        · orden {{ orderStatusLabel(pending.ticket.order_status) }}
                                    </span>
                                </dd>
                            </div>
                        </dl>

                        <div v-if="pending.wristband_color || pending.wristband_label" class="scan-modal__wristband">
                            <span
                                class="scan-modal__dot"
                                :style="{ background: pending.wristband_color || '#000000' }"
                            ></span>
                            Pulsera: {{ pending.wristband_label || 'sin texto' }}
                        </div>
                    </template>

                    <p v-if="pending.message && !pending.can_validate" class="scan-modal__message">
                        {{ pending.message }}
                    </p>

                    <p v-if="validationError" class="scan-modal__error">
                        <i class="bi bi-exclamation-triangle me-1"></i>{{ validationError }}
                    </p>
                </div>

                <footer class="modal-sheet__foot">
                    <button type="button" class="btn btn-et-ghost" @click="cancelPending">
                        {{ pending.can_validate ? 'Cancelar' : 'Cerrar' }}
                    </button>
                    <button
                        v-if="pending.can_validate"
                        type="button"
                        class="btn btn-et-primary"
                        :disabled="validating"
                        @click="confirmValidation"
                    >
                        <span v-if="validating" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="bi bi-check2-circle me-1"></i>
                        Validar entrada
                    </button>
                </footer>
            </div>
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

.stage.is-idle {
    cursor: pointer;
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

/* ------------------------------------------------------ modal de revision */
.scan-modal {
    max-width: 560px;
}

.scan-modal__badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    border-radius: 999px;
    padding: 0.2rem 0.6rem;
    margin-bottom: 0.4rem;
}

.scan-modal__badge--success {
    background: var(--et-success-soft, #e7f7ec);
    color: var(--et-success, #15803d);
}

.scan-modal__badge--warning {
    background: var(--et-warning-soft, #fff4e3);
    color: var(--et-warning, #b45309);
}

.scan-modal__badge--danger {
    background: var(--et-danger-soft, #fdecec);
    color: var(--et-danger, #b91c1c);
}

.scan-modal__title {
    font-size: 1.1rem;
    font-weight: 800;
    margin: 0;
}

.scan-modal__person {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    margin-bottom: 1rem;
}

.scan-modal__avatar {
    width: 3.2rem;
    height: 3.2rem;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: var(--et-primary-soft, #edeaff);
    color: var(--et-primary, #5b4bdb);
    font-weight: 800;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.scan-modal__name {
    font-size: 1.05rem;
    font-weight: 800;
    margin: 0;
}

.scan-modal__meta {
    margin: 0.1rem 0 0;
    color: var(--et-text-muted, #5a6474);
    font-size: 0.85rem;
}

.scan-modal__data {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.6rem 1rem;
    margin: 0 0 1rem;
    padding: 0.85rem 1rem;
    background: var(--et-surface-hover, #f4f6fb);
    border-radius: var(--et-radius-sm, 9px);
}

.scan-modal__data div {
    min-width: 0;
}

.scan-modal__data dt {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--et-text-faint, #8a94a6);
    font-weight: 700;
}

.scan-modal__data dd {
    margin: 0.1rem 0 0;
    font-weight: 600;
    overflow-wrap: anywhere;
}

.scan-modal__wristband {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 0.75rem;
}

.scan-modal__dot {
    width: 0.85rem;
    height: 0.85rem;
    border-radius: 50%;
    border: 1px solid rgb(0 0 0 / 15%);
}

.scan-modal__message {
    margin: 0.5rem 0 0;
    font-size: 0.9rem;
    color: var(--et-text-muted, #5a6474);
}

.scan-modal__error {
    margin: 0.75rem 0 0;
    font-size: 0.85rem;
    color: var(--et-danger, #b91c1c);
}

@media (max-width: 480px) {
    .scan-modal__data {
        grid-template-columns: 1fr;
    }
}
</style>
<script setup>
/**
 * Configuracion y ejecucion de un sorteo.
 *
 * A la izquierda se define el universo de entradas (evento, rango de numeros,
 * rango de fechas, medios de pago y elegibilidad) y los premios. A la derecha,
 * el resumen con cuantas entradas entran en juego, el boton de guardar y el del
 * proyector.
 *
 * Sortear es por premio: el backend elige el ganador (nunca el navegador) y aca
 * solo se lo revela con una animacion. La ventana publica hace lo propio al
 * recibir el aviso por websocket.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { toast } from '../ui/toast.js';
import confirm from '../ui/confirm.js';
import MultiSelectFilter from '../ui/MultiSelectFilter.vue';
import { PAYMENT_FILTERS } from '../paymentMethods.js';

const props = defineProps({
    id: { type: [Number, String], default: null },
});

const emit = defineEmits(['navigate']);

const isNew = computed(() => !props.id);

// MultiSelectFilter trabaja con {id, name}: se arma desde las mismas opciones de
// medio de pago que usa el resto del panel para no duplicar etiquetas.
const PM_ITEMS = PAYMENT_FILTERS
    .filter((option) => option.value !== '')
    .map((option) => ({ id: option.value, name: option.label }));

const STATUSES = [
    { value: 'draft', label: 'Borrador' },
    { value: 'open', label: 'Abierto (en juego)' },
    { value: 'finished', label: 'Finalizado' },
];

const form = reactive({
    event_id: '',
    name: '',
    status: 'draft',
    number_from: '',
    number_to: '',
    date_from: '',
    date_to: '',
    payment_methods: [],
    only_paid: true,
    include_used: true,
    include_cancelled: false,
    allow_repeat_winners: false,
});

const events = ref([]);

/*
| Con un solo evento asignado el selector sobra: se muestra directamente a que
| evento pertenece el sorteo. Quien tenga varios (o el admin) sigue viendo el
| <select>.
*/
const scopedEvent = computed(() => (events.value.length === 1 ? events.value[0] : null));

const raffle = ref(null);
const prizes = ref([]);
const eligibleCount = ref(null);
const loading = ref(false);
const saving = ref(false);
const busyPrize = ref(null);
const error = ref('');
const fieldErrors = ref({});
const anim = ref(null);

/* El input datetime-local no acepta ISO con zona; se corta hasta el minuto. */
function toLocalInput(value) {
    if (!value) {
        return '';
    }

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) {
        return '';
    }

    const pad = (n) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function toIso(value) {
    if (!value) {
        return null;
    }

    return new Date(value).toISOString();
}

function invalid(field) {
    return fieldErrors.value[field]?.[0] || '';
}

function blankPrize() {
    return { id: null, name: '', description: '', ticket_number: null, winner_name: null, drawn_at: null };
}

async function loadEvents() {
    // El administrativo recibe solo los eventos que tiene asignados.
    const { data } = await api.get('tickets-admin/events', { all: 1 });

    events.value = data || [];
}

async function load() {
    loading.value = true;

    try {
        await loadEvents();

        if (isNew.value) {
            if (events.value.length === 1) {
                form.event_id = events.value[0].id;
            }

            prizes.value = [blankPrize()];

            return;
        }

        const { data } = await api.get(`tickets-admin/raffles/${props.id}`);

        raffle.value = data;
        form.event_id = data.event_id;
        form.name = data.name;
        form.status = data.status;
        form.number_from = data.number_from ?? '';
        form.number_to = data.number_to ?? '';
        form.date_from = toLocalInput(data.date_from);
        form.date_to = toLocalInput(data.date_to);
        form.payment_methods = Array.isArray(data.payment_methods) ? [...data.payment_methods] : [];
        form.only_paid = !!data.only_paid;
        form.include_used = !!data.include_used;
        form.include_cancelled = !!data.include_cancelled;
        form.allow_repeat_winners = !!data.allow_repeat_winners;

        prizes.value = (data.prizes || []).map((p) => ({
            id: p.id,
            name: p.name || '',
            description: p.description || '',
            ticket_number: p.ticket_number,
            winner_name: p.winner_name,
            drawn_at: p.drawn_at,
        }));

        await loadEligible();
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

async function loadEligible() {
    if (isNew.value || !raffle.value) {
        return;
    }

    try {
        const { data } = await api.get(`tickets-admin/raffles/${raffle.value.id}/eligible`);

        eligibleCount.value = data.count;
    } catch {
        eligibleCount.value = null;
    }
}

function addPrize() {
    prizes.value.push(blankPrize());
}

async function removePrize(index) {
    const prize = prizes.value[index];

    if (prizes.value.length <= 1) {
        toast('Tiene que quedar al menos un premio', 'warning');

        return;
    }

    if (prize.id && prize.ticket_number !== null && prize.ticket_number !== undefined) {
        toast('No se puede quitar un premio ya sorteado. Reinicialo primero.', 'warning');

        return;
    }

    prizes.value.splice(index, 1);
}

function prizeLabel(prize, index) {
    return prize.name || `Premio ${index + 1}`;
}

function payload() {
    const toIntOrNull = (v) => (v === '' || v == null) ? null : Number(v);

    return {
        event_id: form.event_id ? Number(form.event_id) : null,
        name: form.name.trim(),
        status: form.status,
        number_from: toIntOrNull(form.number_from),
        number_to: toIntOrNull(form.number_to),
        date_from: toIso(form.date_from),
        date_to: toIso(form.date_to),
        payment_methods: [...form.payment_methods],
        only_paid: form.only_paid,
        include_used: form.include_used,
        include_cancelled: form.include_cancelled,
        allow_repeat_winners: form.allow_repeat_winners,
        prizes: prizes.value.map((p) => ({
            name: p.name ? p.name.trim() : null,
            description: p.description || null,
        })),
    };
}

async function save() {
    if (saving.value) {
        return;
    }

    saving.value = true;
    error.value = '';
    fieldErrors.value = {};

    try {
        if (isNew.value) {
            const { data } = await api.post('tickets-admin/raffles', payload());

            toast('Sorteo creado', 'success');
            emit('navigate', 'raffle-edit', { id: data.id });

            return;
        }

        await api.put(`tickets-admin/raffles/${raffle.value.id}`, payload());

        toast('Sorteo guardado', 'success');
        await load();
    } catch (err) {
        const formatted = toError(err);

        error.value = formatted.message;
        fieldErrors.value = formatted.errors;
    } finally {
        saving.value = false;
    }
}

/* --------------------------------------------------------------- sorteo */

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function revealDraw(winner) {
    anim.value = {
        prizeName: winner.prize_name || `Premio ${winner.position}`,
        display: '0000',
        ticketNumber: winner.ticket_number,
        winnerName: winner.winner_name,
        phase: 'spinning',
    };

    const started = Date.now();
    const duration = 2400;

    while (Date.now() - started < duration) {
        anim.value.display = String(Math.floor(Math.random() * 9999) + 1).padStart(4, '0');
        await sleep(55);
    }

    anim.value.display = winner.ticket_number != null
        ? String(winner.ticket_number).padStart(4, '0')
        : '—';
    anim.value.phase = 'revealed';

    await sleep(3200);
    anim.value = null;
}

async function drawPrize(prize) {
    if (!raffle.value || busyPrize.value != null || anim.value) {
        return;
    }

    busyPrize.value = prize.id;

    try {
        const { data } = await api.post(`tickets-admin/raffles/${raffle.value.id}/prizes/${prize.id}/draw`);
        const winner = data.winner;

        await revealDraw(winner);

        prize.ticket_number = winner.ticket_number;
        prize.winner_name = winner.winner_name;
        prize.drawn_at = new Date().toISOString();

        await loadEligible();
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        busyPrize.value = null;
    }
}

async function resetPrize(prize) {
    const ok = await confirm({
        title: 'Volver a sortear',
        message: `Se borra el ganador de "${prize.name || 'Premio'}" y queda listo para sortear de nuevo.`,
        confirmLabel: 'Volver a sortear',
        tone: 'danger',
    });

    if (!ok) {
        return;
    }

    busyPrize.value = prize.id;

    try {
        await api.post(`tickets-admin/raffles/${raffle.value.id}/prizes/${prize.id}/reset`);

        prize.ticket_number = null;
        prize.winner_name = null;
        prize.drawn_at = null;

        toast('Premio reinicializado', 'success');
        await loadEligible();
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        busyPrize.value = null;
    }
}

function openProjector() {
    if (!raffle.value?.public_token) {
        return;
    }

    window.open(`/sorteo/${raffle.value.public_token}`, '_blank', 'noopener');
}

onMounted(load);
onBeforeUnmount(() => {
    anim.value = null;
});
</script>

<template>
    <div>
        <button class="btn btn-link btn-sm p-0 mb-2" @click="emit('navigate', 'raffles')">
            <i class="bi bi-arrow-left me-1"></i>Sorteos
        </button>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <form v-else class="row g-3" @submit.prevent="save">
            <div class="col-12 col-lg-7">
                <section class="et-surface-raised p-3 mb-3">
                    <h2 class="h6 fw-bold mb-3">{{ isNew ? 'Nuevo sorteo' : 'Datos del sorteo' }}</h2>

                    <div class="mb-3">
                        <template v-if="scopedEvent">
                            <label class="form-label">Evento</label>
                            <p class="form-control-plaintext mb-0">
                                <i class="bi bi-calendar-event me-1"></i>{{ scopedEvent.name }}
                            </p>
                        </template>

                        <template v-else>
                            <label class="form-label" for="event">Evento</label>
                            <select
                                id="event"
                                v-model="form.event_id"
                                class="form-select"
                                :class="{ 'is-invalid': invalid('event_id') }"
                            >
                                <option value="" disabled>Elegí un evento</option>
                                <option v-for="ev in events" :key="ev.id" :value="ev.id">{{ ev.name }}</option>
                            </select>
                            <div v-if="invalid('event_id')" class="invalid-feedback">{{ invalid('event_id') }}</div>
                        </template>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="name">Nombre del sorteo</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': invalid('name') }"
                            placeholder="Rifa principal"
                        >
                        <div v-if="invalid('name')" class="invalid-feedback">{{ invalid('name') }}</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="number_from">Entrada desde (N°)</label>
                            <input id="number_from" v-model="form.number_from" type="number" min="1" class="form-control numeric" placeholder="1">
                        </div>

                        <div class="col-6">
                            <label class="form-label" for="number_to">Entrada hasta (N°)</label>
                            <input id="number_to" v-model="form.number_to" type="number" min="1" class="form-control numeric" placeholder="Todas">
                        </div>

                        <div class="col-6">
                            <label class="form-label" for="date_from">Desde (fecha de compra)</label>
                            <input id="date_from" v-model="form.date_from" type="datetime-local" class="form-control">
                        </div>

                        <div class="col-6">
                            <label class="form-label" for="date_to">Hasta (fecha de compra)</label>
                            <input id="date_to" v-model="form.date_to" type="datetime-local" class="form-control">
                        </div>
                    </div>
                </section>

                <section class="et-surface-raised p-3 mb-3">
                    <h2 class="h6 fw-bold mb-3">Medios de pago en juego</h2>
                    <p class="small text-muted-2">
                        Elegi con que medios se pagaron las entradas que participan. Sin ninguno elegido, entran todas.
                    </p>

                    <MultiSelectFilter
                        v-model="form.payment_methods"
                        :items="PM_ITEMS"
                        placeholder="Buscar medio de pago..."
                        none-text="Todos los medios"
                        empty-text="Sin resultados"
                    />
                </section>

                <section class="et-surface-raised p-3 mb-3">
                    <h2 class="h6 fw-bold mb-3">Elegibilidad</h2>

                    <div class="form-check form-switch mb-2">
                        <input id="only_paid" v-model="form.only_paid" class="form-check-input" type="checkbox">
                        <label class="form-check-label" for="only_paid">Solo entradas pagas</label>
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input id="include_used" v-model="form.include_used" class="form-check-input" type="checkbox">
                        <label class="form-check-label" for="include_used">Incluir entradas ya usadas</label>
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input id="include_cancelled" v-model="form.include_cancelled" class="form-check-input" type="checkbox">
                        <label class="form-check-label" for="include_cancelled">Incluir entradas canceladas</label>
                    </div>

                    <div class="form-check form-switch mb-0">
                        <input id="allow_repeat_winners" v-model="form.allow_repeat_winners" class="form-check-input" type="checkbox">
                        <label class="form-check-label" for="allow_repeat_winners">Permitir que una entrada gane mas de un premio</label>
                    </div>
                </section>

                <section class="et-surface-raised p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h6 fw-bold mb-0">Premios</h2>
                        <button type="button" class="btn btn-et-ghost btn-sm" @click="addPrize">
                            <i class="bi bi-plus-lg me-1"></i>Agregar premio
                        </button>
                    </div>

                    <p v-if="invalid('prizes')" class="small" style="color: var(--et-danger)">
                        <i class="bi bi-exclamation-triangle me-1"></i>{{ invalid('prizes') }}
                    </p>

                    <article v-for="(prize, index) in prizes" :key="prize.id || `new-${index}`" class="prize">
                        <div class="prize__head">
                            <span class="prize__pos numeric">{{ index + 1 }}</span>
                            <input v-model="prize.name" type="text" class="form-control form-control-sm" placeholder="Nombre del premio">
                            <button
                                type="button"
                                class="btn btn-et-ghost btn-sm btn-danger-soft"
                                title="Quitar premio"
                                @click="removePrize(index)"
                            >
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>

                        <input
                            v-model="prize.description"
                            type="text"
                            class="form-control form-control-sm mt-2"
                            placeholder="Descripcion (opcional)"
                        >

                        <div v-if="!isNew && prize.id" class="prize__foot">
                            <template v-if="prize.ticket_number !== null && prize.ticket_number !== undefined">
                                <span class="prize__winner">
                                    <i class="bi bi-trophy-fill me-1"></i>
                                    N° {{ prize.ticket_number }} · {{ prize.winner_name || 'Sin nombre' }}
                                </span>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-et-ghost btn-sm" :disabled="busyPrize === prize.id" @click="drawPrize(prize)">
                                        <i class="bi bi-arrow-repeat me-1"></i>Sortear de nuevo
                                    </button>
                                    <button type="button" class="btn btn-et-ghost btn-sm btn-danger-soft" :disabled="busyPrize === prize.id" @click="resetPrize(prize)">
                                        Reinicializar
                                    </button>
                                </div>
                            </template>

                            <template v-else>
                                <span class="small text-faint">Sin sortear</span>
                                <button type="button" class="btn btn-et-primary btn-sm" :disabled="busyPrize === prize.id || !!anim" @click="drawPrize(prize)">
                                    <span v-if="busyPrize === prize.id" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="bi bi-play-fill me-1"></i>Sortear
                                </button>
                            </template>
                        </div>
                    </article>
                </section>
            </div>

            <div class="col-12 col-lg-5">
                <div class="sticky-side">
                    <section class="et-surface-raised p-3 mb-3">
                        <h2 class="h6 fw-bold mb-3">Resumen</h2>

                        <div class="summary-row">
                            <span class="text-muted-2 small">Entradas en juego</span>
                            <span class="fw-bold numeric">
                                <template v-if="eligibleCount === null">—</template>
                                <template v-else>{{ eligibleCount }}</template>
                            </span>
                        </div>

                        <div class="summary-row">
                            <span class="text-muted-2 small">Premios</span>
                            <span class="fw-bold numeric">{{ prizes.length }}</span>
                        </div>

                        <button
                            v-if="!isNew"
                            type="button"
                            class="btn btn-et-ghost w-100 mt-2"
                            @click="loadEligible"
                        >
                            <i class="bi bi-arrow-clockwise me-1"></i>Recalcular entradas
                        </button>
                    </section>

                    <section v-if="!isNew" class="et-surface-raised p-3 mb-3">
                        <h2 class="h6 fw-bold mb-2">Proyeccion</h2>
                        <p class="small text-muted-2">
                            Abre una ventana publica (sin sesion) para mostrar los resultados en una pantalla o proyector.
                        </p>
                        <button type="button" class="btn btn-et-primary w-100" @click="openProjector">
                            <i class="bi bi-display me-1"></i>Abrir ventana de proyeccion
                        </button>
                    </section>

                    <section class="et-surface-raised p-3">
                        <h2 class="h6 fw-bold mb-3">Estado</h2>

                        <div class="mb-3">
                            <label class="form-label" for="status">Situacion</label>
                            <select id="status" v-model="form.status" class="form-select">
                                <option v-for="option in STATUSES" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <p v-if="error" class="small" style="color: var(--et-danger)">
                            <i class="bi bi-exclamation-triangle me-1"></i>{{ error }}
                        </p>

                        <button class="btn btn-et-primary w-100" type="submit" :disabled="saving">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
                            {{ isNew ? 'Crear sorteo' : 'Guardar cambios' }}
                        </button>
                    </section>
                </div>
            </div>
        </form>

        <!-- Animacion del sorteo: el ganador ya lo eligio el servidor; aca solo
             se lo revela para la pantalla. -->
        <div v-if="anim" class="draw-overlay">
            <div class="draw-card" :class="{ 'is-revealed': anim.phase === 'revealed' }">
                <p class="draw__prize">{{ anim.prizeName }}</p>
                <div class="draw__number numeric">{{ anim.display }}</div>
                <p v-if="anim.phase === 'revealed'" class="draw__winner">
                    <i class="bi bi-trophy-fill me-1"></i>{{ anim.winnerName || 'Sin nombre' }}
                </p>
                <p v-else class="draw__hint">Sorteando…</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.sticky-side {
    position: sticky;
    top: 4.5rem;
}

@media (max-width: 991.98px) {
    .sticky-side {
        position: static;
    }
}

.summary-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.35rem 0;
    border-bottom: 1px dashed var(--et-border);
}

.summary-row:last-of-type {
    border-bottom: 0;
}

.prize {
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius-sm);
    padding: 0.75rem;
    margin-bottom: 0.6rem;
}

.prize__head {
    display: grid;
    grid-template-columns: 32px minmax(0, 1fr) auto;
    gap: 0.5rem;
    align-items: center;
}

.prize__pos {
    width: 32px;
    height: 32px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--et-primary-soft);
    color: var(--et-primary);
    font-weight: 700;
    font-size: 0.85rem;
}

.prize__foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-top: 0.7rem;
    padding-top: 0.7rem;
    border-top: 1px dashed var(--et-border);
}

.prize__winner {
    font-weight: 600;
    color: var(--et-primary);
}

.draw-overlay {
    position: fixed;
    inset: 0;
    z-index: 2000;
    display: grid;
    place-items: center;
    background: rgb(0 0 0 / 80%);
    backdrop-filter: blur(4px);
}

.draw-card {
    text-align: center;
    padding: 2.5rem 3rem;
    border-radius: var(--et-radius-lg, 18px);
    background: #12131a;
    color: #fff;
    min-width: min(560px, 90vw);
    box-shadow: 0 24px 80px rgb(0 0 0 / 55%);
}

.draw__prize {
    font-size: 1.1rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #9aa0b4;
    margin-bottom: 0.75rem;
}

.draw__number {
    font-size: clamp(4rem, 16vw, 9rem);
    font-weight: 800;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    color: #fff;
    transition: color 0.3s ease, transform 0.3s ease;
}

.draw-card.is-revealed .draw__number {
    color: #facc15;
    transform: scale(1.06);
}

.draw__winner {
    margin-top: 1.25rem;
    font-size: 1.6rem;
    font-weight: 700;
    color: #fff;
}

.draw__hint {
    margin-top: 1.25rem;
    font-size: 1rem;
    color: #9aa0b4;
}
</style>

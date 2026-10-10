<script setup>
/**
 * Detalle de un evento: elegir cuantos boletos de cada tipo.
 *
 * El precio y el stock NO se calculan aca: se muestran los que manda el
 * backend. Si el frontend hiciera la cuenta, dos personas viendo la misma
 * pantalla con distinta informacion Cache-Control verian totales distintos, y
 * el que paga es el que se equivoca.
 *
 * Lo que si hace el frontend es limitar los controles a lo que el backend dice
 * que se puede pedir (remaining, max_per_order, ventana de venta), para que
 * el error 422 sea la excepcion y no la norma.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import api, { toError } from '../api.js';
import { listen, startPolling, stopPolling } from '../realtime.js';
import { rememberOrder } from '../shell/router.js';
import { toast } from '../ui/toast.js';
import EmptyState from '../ui/EmptyState.vue';
import AdSlot from './AdSlot.vue';
import ImageFlyer from '../ui/ImageFlyer.vue';

const props = defineProps({
    slug: { type: String, required: true },
    user: { type: Object, default: null },
    config: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['navigate']);

const event = ref(null);
const loading = ref(true);
const error = ref('');
const qty = reactive({});

let unsubscribeStock = null;

async function load() {
    try {
        const { data } = await api.get(`tickets-portal/api/events/${encodeURIComponent(props.slug)}`);

        event.value = data;

        // Se pisa la cantidad solo para los tipos nuevos: si el comprador ya
        // eligio 3 y llega un evento de stock, no se le pisa la eleccion.
        (data.ticket_types || []).forEach((type) => {
            if (qty[type.id] === undefined) {
                qty[type.id] = 0;
            }
        });
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

const types = computed(() => event.value?.ticket_types || []);

const purchasable = computed(() => types.value.filter((t) => t.available));

const items = computed(() => Object.entries(qty)
    .filter(([, q]) => q > 0)
    .map(([id, q]) => ({ ticket_type_id: Number(id), qty: q })));

const total = computed(() => items.value.reduce((sum, item) => {
    const type = types.value.find((t) => t.id === item.ticket_type_id);

    return sum + Number(type?.price || 0) * item.qty;
}, 0));

const count = computed(() => items.value.reduce((sum, item) => sum + item.qty, 0));

const canCheckout = computed(() => count.value > 0 && total.value > 0);

const buying = ref(false);
const buyError = ref('');

/*
| El modo de pago es de la orden completa, no del boleto: si se financia con
| cuotas, TODOS los tipos elegidos tienen que admitirlas. Por eso el calculo
| usa los tipos con cantidad > 0 y el mas restrictivo, no el mas permisivo. Con
| el maximo se ofrecia una combinacion que el backend rebutla con 422, y el
| comprador perdia el viaje entero por una opcion que la pantalla le ofrecio.
*/
const selectedTypes = computed(() => items.value
    .map((item) => types.value.find((t) => t.id === item.ticket_type_id))
    .filter(Boolean));

const supportsInstallments = computed(() => selectedTypes.value.length > 0
    && selectedTypes.value.every((t) => t.payment_mode !== 'single' && Number(t.max_installments) >= 2));

const maxInstallments = computed(() => {
    if (!supportsInstallments.value) {
        return 1;
    }

    // El limite es el del tipo MAS restrictivo del canasto, no el mas alto:
    // con un tipo de 3 y otro de 6, pedir 6 cuotas lo rechaza el backend porque
    // el de 3 no las tiene. Math.max ofrecia una opcion que el 422 hacia fallar.
    return Math.min(...selectedTypes.value.map((t) => Number(t.max_installments) || 1));
});

/**
 * Por que el selector se limita a lo que el backend dice que se puede pedir
 * (remaining, max_per_order, ventana de venta): para que el error 422 sea la
 * excepcion y no la norma.
 */
function limitFor(type) {
    // El menor entre lo que queda y el maximo por compra. Un tipo sin stock
    // (stock null) no acota por stock.
    const byStock = type.remaining === null ? type.max_per_order : type.remaining;

    return Math.max(0, Math.min(type.max_per_order, byStock));
}

function step(type, delta) {
    const next = (qty[type.id] || 0) + delta;

    qty[type.id] = Math.max(0, Math.min(next, limitFor(type)));
}

function soldOut(type) {
    return type.remaining !== null && type.remaining <= 0;
}

function almostGone(type) {
    return type.remaining !== null && type.remaining > 0 && type.remaining <= 10;
}

function money(value) {
    return new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        minimumFractionDigits: 0,
    }).format(Number(value || 0));
}

function formatDate(value) {
    if (!value) {
        return 'Fecha a confirmar';
    }

    return new Date(value).toLocaleDateString('es-AR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function formatTime(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
}

function goToCheckout() {
    if (!canCheckout.value || buying.value) {
        return;
    }

    /*
    | Sin pantalla intermedia de "Confirmar compra": el cliente ya selecciono
    | tipo y cantidad aca mismo. Se crea la orden, se pide la preference de MP
    | y se salta a su checkout. Ahi el cliente elige la tarjeta y (si el evento
    | admite cuotas) cuantas cuotas quiere; el costo real lo determina el.
    |
    | installment_count va con el maximo que soporta el evento: MP usa ese
    | valor como techo y le ofrece al cliente todas las opciones que tenga
    | habilitadas. payment_mode queda 'installments' en la DB para reflejar la
    | intencion, pero el numero exacto lo elige el cliente en MP.
    */
    buying.value = true;
    buyError.value = '';

    const payload = {
        event_id: Number(event.value.id),
        items: items.value,
        payment_mode: supportsInstallments.value ? 'installments' : 'single',
        installment_count: supportsInstallments.value ? maxInstallments.value : null,
    };

    (async () => {
        try {
            const { data: order } = await api.post('tickets-portal/api/orders', payload);

            rememberOrder({
                order_id: order.order_id,
                token: order.public_token,
                event_id: Number(event.value.id),
            });

            /* Medios habilitados para el evento: con un unico MP se salta
            directo a su checkout; con Multipago (o varios) se pasa por la
            pantalla de eleccion. */
            const { data: pm } = await api.get(
                `tickets-portal/api/orders/${order.order_id}/payment-methods`
            );

            const methods = pm.methods || [];

            if (methods.length === 0) {
                buyError.value = 'Este evento no tiene medios de pago habilitados por el momento.';
                buying.value = false;

                return;
            }

            if (methods.length === 1 && methods[0].type === 'redirect') {
                const { data: preference } = await api.post(
                    `tickets-portal/api/orders/${order.order_id}/preference`
                );

                const target = preference.init_point || preference.sandbox_init_point;

                if (!target) {
                    buyError.value = 'El medio no devolvio una URL de pago. Intenta de nuevo.';
                    buying.value = false;

                    return;
                }

                window.location.href = target;

                return;
            }

            buying.value = false;
            emit('navigate', 'payment', { id: order.order_id });
        } catch (err) {
            buyError.value = toError(err).message;
            buying.value = false;

            toast(buyError.value, 'danger');
        }
    })();
}

onMounted(async () => {
    await load();

    unsubscribeStock = listen('tickets.events', 'stock.changed', load);
    startPolling(load);
});

onBeforeUnmount(() => {
    unsubscribeStock?.();
    stopPolling();
});
</script>

<template>
    <div v-if="loading" class="text-center py-5">
        <div class="spinner-border" role="status"></div>
    </div>

    <EmptyState
        v-else-if="!event"
        icon="bi-search"
        title="No encontramos ese evento"
        :hint="error"
    >
        <button class="btn btn-et-ghost btn-sm" @click="emit('navigate', 'events')">Ver todos</button>
    </EmptyState>

    <div v-else class="detail">
        <button class="btn btn-link text-muted-2 p-0 mb-3 no-print" @click="emit('navigate', 'events')">
            <i class="bi bi-arrow-left me-1"></i>Todos los eventos
        </button>

        <header class="detail__hero et-surface">
            <div class="detail__cover">
                <img v-if="event.cover_image" :src="event.cover_image" :alt="event.name">
                <div v-else class="detail__cover-fallback">
                    <i class="bi bi-ticket-perforated"></i>
                </div>
            </div>

            <div class="detail__hero-body">
                <h1 class="h3 fw-bold mb-2">{{ event.name }}</h1>

                <p class="text-muted-2 mb-2">
                    <i class="bi bi-calendar-event me-2"></i>{{ formatDate(event.starts_at) }}
                    <span v-if="formatTime(event.starts_at)" class="numeric">
                        {{ formatTime(event.starts_at) }}
                    </span>
                </p>

                <p v-if="event.location" class="text-muted-2 mb-0">
                    <i class="bi bi-geo-alt me-2"></i>{{ event.location }}
                </p>

                <p v-if="event.description" class="detail__desc mt-3 mb-0">
                    {{ event.description }}
                </p>
            </div>
        </header>

        <section class="mt-4">
            <h2 class="h6 fw-bold text-uppercase text-faint mb-3" style="letter-spacing: 0.06em">
                Entradas
            </h2>

            <EmptyState
                v-if="!purchasable.length"
                icon="bi-hourglass"
                title="No hay entradas disponibles"
                hint="Puede que se hayan agotado o que la venta todavia no haya abierto."
            />

            <div v-else class="d-flex flex-column gap-2">
                <article
                    v-for="type in purchasable"
                    :key="type.id"
                    class="type et-surface"
                >
                    <ImageFlyer
                        :src="type.image_path"
                        :alt="`Flayer de ${type.name}`"
                        thumb-class="type__img"
                    />

                    <div class="type__body">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h3 class="mb-0 fw-bold">{{ type.name }}</h3>

                            <span v-if="type.wristband_color" class="chip">
                                <span class="chip__dot" :style="{ background: type.wristband_color }"></span>
                                {{ type.wristband_label || type.name }}
                            </span>

                            <span v-if="soldOut(type)" class="chip chip--danger">Agotado</span>
                            <span v-else-if="almostGone(type)" class="chip chip--warning">
                                Quedan {{ type.remaining }}
                            </span>
                        </div>

                        <p v-if="type.description" class="text-muted-2 small mb-0 mt-1">
                            {{ type.description }}
                        </p>

                        <p class="type__notes small mb-0 mt-2">
                            <span v-if="type.remaining !== null">
                                <i class="bi bi-ticket-perforated me-1"></i>{{ type.remaining }} disponibles
                            </span>
                            <span v-if="type.max_per_order">
                                · máx. {{ type.max_per_order }} por compra
                            </span>
                            <span v-if="type.payment_mode !== 'single' && type.max_installments >= 2">
                                · hasta {{ type.max_installments }} cuotas
                            </span>
                        </p>
                    </div>

                    <div class="type__buy">
                        <span class="type__price">{{ money(type.price) }}</span>

                        <div class="stepper" role="group" aria-label="Cantidad">
                            <button
                                class="stepper__btn"
                                :disabled="!qty[type.id]"
                                aria-label="Quitar una"
                                @click="step(type, -1)"
                            >
                                <i class="bi bi-dash"></i>
                            </button>

                            <span class="stepper__value numeric">{{ qty[type.id] || 0 }}</span>

                            <button
                                class="stepper__btn"
                                :disabled="(qty[type.id] || 0) >= limitFor(type)"
                                aria-label="Agregar una"
                                @click="step(type, 1)"
                            >
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <div v-if="event.ads?.length" class="mt-4">
            <a
                v-for="ad in event.ads"
                :key="ad.id"
                :href="ad.target_url || '#'"
                :target="ad.target_url ? '_blank' : undefined"
                rel="noopener"
                class="d-block mb-2"
            >
                <img :src="ad.image_path" :alt="ad.name" class="w-100 rounded" loading="lazy">
            </a>
        </div>
    </div>

    <!-- Barra de resumen pegada abajo: el comprador no tiene que volver
         arriba a ver cuanto lleva. -->
    <div v-if="count > 0" class="summary no-print">
        <div class="summary__inner">
            <div class="summary__info">
                <span class="numeric fw-bold fs-5">{{ money(total) }}</span>
                <span class="text-muted-2 small">
                    {{ count }} {{ count === 1 ? 'entrada' : 'entradas' }}
                </span>
            </div>

            <button class="btn btn-et-primary" :disabled="!canCheckout || buying" @click="goToCheckout">
                <span v-if="buying" class="spinner-border spinner-border-sm me-2"></span>
                {{ buying ? 'Abriendo MercadoPago…' : 'Comprar' }}
                <i v-if="!buying" class="bi bi-arrow-right ms-1"></i>
            </button>
        </div>
    </div>
</template>

<style scoped>
.detail__hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    overflow: hidden;
}

@media (min-width: 768px) {
    .detail__hero {
        grid-template-columns: minmax(0, 420px) minmax(0, 1fr);
    }
}

.detail__cover {
    aspect-ratio: 16 / 10;
    background: var(--et-surface-hover);
}

@media (min-width: 768px) {
    .detail__cover {
        height: 100%;
        aspect-ratio: auto;
    }
}

.detail__cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.detail__cover-fallback {
    height: 100%;
    display: grid;
    place-items: center;
    font-size: 3rem;
    color: var(--et-text-faint);
    background: linear-gradient(135deg, var(--et-primary-soft), transparent);
}

.detail__hero-body {
    padding: 1.5rem;
}

.detail__desc {
    color: var(--et-text-muted);
    white-space: pre-line;
    max-width: 62ch;
}

.type {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 1rem;
    align-items: center;
    padding: 0.9rem 1rem;
}

/*
| :deep() para que las clases thumb-class aplicadas al <button> raiz del
| ImageFlyer matcheen desde aca. Sin esto el selector quedaria
| .type__img[data-v-xxx] y el button renderizado solo lleva su propio
| data-v-xxx (el del ImageFlyer), asi que la regla no aplicaba y la
| imagen quedaba a su tamano natural.
*/
.type :deep(.type__img) {
    width: 64px;
    height: 64px;
    border-radius: var(--et-radius-sm);
}

.type__img--placeholder {
    display: grid;
    place-items: center;
    font-size: 1.5rem;
    color: var(--et-text-faint);
    background: var(--et-surface-hover);
}

.type__notes {
    color: var(--et-text-faint);
}

.type__buy {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.type__price {
    font-weight: 700;
    white-space: nowrap;
}

.chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
    border: 1px solid var(--et-border-strong);
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--et-text-faint);
}

.chip__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.chip--danger {
    border-color: var(--et-danger);
    color: var(--et-danger);
}

.chip--warning {
    border-color: var(--et-warning);
    color: var(--et-warning);
}

.stepper {
    display: inline-flex;
    align-items: center;
    border: 1px solid var(--et-border-strong);
    border-radius: var(--et-radius-sm);
    overflow: hidden;
}

.stepper__btn {
    width: 34px;
    height: 34px;
    border: 0;
    background: none;
    color: var(--et-text);
    transition: background-color var(--et-transition);
}

.stepper__btn:hover:not(:disabled) {
    background: var(--et-surface-hover);
}

.stepper__btn:disabled {
    color: var(--et-text-faint);
    cursor: not-allowed;
}

.stepper__value {
    min-width: 34px;
    text-align: center;
    font-weight: 600;
}

.summary {
    position: sticky;
    bottom: 0;
    z-index: 20;
    background: rgb(10 10 15 / 90%);
    backdrop-filter: blur(14px);
    border-top: 1px solid var(--et-border);
}

.summary__inner {
    max-width: var(--et-shell-max);
    margin: 0 auto;
    padding: 0.85rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.summary__info {
    display: flex;
    align-items: baseline;
    gap: 0.6rem;
}

@media (max-width: 575.98px) {
    .type {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .type__buy {
        grid-column: 1 / -1;
        justify-content: space-between;
    }
}
</style>
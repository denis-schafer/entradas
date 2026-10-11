<script setup>
/**
 * POS de la caja: venta en efectivo.
 *
 * El flujo es el de un mostrador: elegir el evento, cargar entradas, resolver
 * el comprador (buscar por DNI: si existe se selecciona, si no se crea con la
 * contrasena por defecto = DNI) y cerrar la venta. El cobro es en efectivo, asi
 * que la orden queda pagada en el acto y se muestra el comprobante para
 * imprimir desde la PC local.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import api, { toError } from '../api.js';
import EmptyState from '../ui/EmptyState.vue';
import { toast } from '../ui/toast.js';

const props = defineProps({ user: { type: Object, required: true } });

const events = ref([]);
const eventId = ref('');
const types = ref([]);
const loading = ref(true);
const loadingTypes = ref(false);
const error = ref('');

const qty = reactive({});

const buyerSearch = ref('');
const buyerResults = ref([]);
const searchingBuyer = ref(false);
const selectedBuyer = ref(null);
const showCreateBuyer = ref(false);
const newBuyer = reactive({ name: '', dni: '', phone: '', email: '' });
const creatingBuyer = ref(false);
const buyerError = ref('');

const selling = ref(false);
const saleError = ref('');
const receipt = ref(null);

const entryLink = computed(() => {
    if (!receipt.value?.order?.public_token) {
        return '';
    }

    return `${window.location.origin}/e/${receipt.value.order.public_token}`;
});

function copyEntryLink() {
    const link = entryLink.value;

    if (!link) {
        return;
    }

    navigator.clipboard
        .writeText(link)
        .then(() => toast('Link copiado. Mandalo al comprador.', 'success'))
        .catch(() => toast('No se pudo copiar el link.', 'danger'));
}

function sendByWhatsApp() {
    const link = entryLink.value;

    if (!link) {
        return;
    }

    const text = `Tus entradas para ${receipt.value.order.event_name || 'el evento'} estan aca: ${link}`;

    window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank', 'noopener');
}

function money(value) {
    return new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        minimumFractionDigits: 0,
    }).format(Number(value || 0));
}

const cartItems = computed(() =>
    types.value
        .filter((type) => Number(qty[type.id] || 0) > 0)
        .map((type) => ({
            ticket_type_id: type.id,
            name: type.name,
            qty: Number(qty[type.id]),
            unit_price: Number(type.price),
            subtotal: Number(type.price) * Number(qty[type.id]),
        })),
);

const cartTotal = computed(() => cartItems.value.reduce((sum, item) => sum + item.subtotal, 0));
const cartCount = computed(() => cartItems.value.reduce((sum, item) => sum + item.qty, 0));

function remaining(type) {
    if (type.stock === null || type.stock === undefined) {
        return null;
    }

    return Number(type.stock) - Number(type.sold_count || 0);
}

function maxFor(type) {
    const limit = Number(type.max_per_order || 10);
    const left = remaining(type);

    return left === null ? limit : Math.min(limit, left);
}

function setQty(type, value) {
    const max = maxFor(type);
    const next = Math.max(0, Math.min(max, Number(value) || 0));

    qty[type.id] = next;
}

function bump(type, delta) {
    setQty(type, Number(qty[type.id] || 0) + delta);
}

async function loadEvents() {
    loading.value = true;

    try {
        const { data } = await api.get('tickets-admin/pos/events');
        events.value = data || [];
        error.value = '';

        if (events.value.length === 1) {
            eventId.value = events.value[0].id;
            await loadTypes();
        }
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

async function loadTypes() {
    if (!eventId.value) {
        types.value = [];
        return;
    }

    loadingTypes.value = true;

    try {
        const { data } = await api.get(`tickets-admin/pos/events/${eventId.value}/ticket-types`);

        types.value = data.ticket_types || [];

        for (const key of Object.keys(qty)) {
            delete qty[key];
        }
    } catch (err) {
        toast(toError(err).message, 'danger');
        types.value = [];
    } finally {
        loadingTypes.value = false;
    }
}

async function searchBuyer() {
    const dni = (buyerSearch.value || '').replace(/\D/g, '');

    if (!dni) {
        return;
    }

    searchingBuyer.value = true;
    buyerError.value = '';
    showCreateBuyer.value = false;

    try {
        const { data } = await api.get('tickets-admin/pos/buyers', { dni });
        buyerResults.value = data || [];

        if (buyerResults.value.length === 1) {
            selectBuyer(buyerResults.value[0]);
        } else if (buyerResults.value.length === 0) {
            newBuyer.dni = dni;
            showCreateBuyer.value = true;
        }
    } catch (err) {
        buyerError.value = toError(err).message;
    } finally {
        searchingBuyer.value = false;
    }
}

function selectBuyer(buyer) {
    selectedBuyer.value = buyer;
    buyerResults.value = [];
    showCreateBuyer.value = false;
    buyerSearch.value = buyer.dni || '';
}

function clearBuyer() {
    selectedBuyer.value = null;
    buyerResults.value = [];
    showCreateBuyer.value = false;
    buyerSearch.value = '';
    newBuyer.name = '';
    newBuyer.dni = '';
    newBuyer.phone = '';
    newBuyer.email = '';
}

async function createBuyer() {
    creatingBuyer.value = true;
    buyerError.value = '';

    try {
        const { data } = await api.post('tickets-admin/pos/buyers', {
            name: newBuyer.name,
            dni: newBuyer.dni,
            phone: newBuyer.phone || null,
            email: newBuyer.email || null,
        });

        selectBuyer(data);
        toast('Comprador creado', 'success');
    } catch (err) {
        buyerError.value = toError(err).message;
    } finally {
        creatingBuyer.value = false;
    }
}

async function confirmSale() {
    saleError.value = '';

    if (!cartCount.value) {
        saleError.value = 'Carga al menos una entrada.';
        return;
    }

    if (!selectedBuyer.value) {
        saleError.value = 'Busca y selecciona el comprador.';
        return;
    }

    selling.value = true;

    try {
        const { data } = await api.post('tickets-admin/pos/sales', {
            event_id: Number(eventId.value),
            buyer_id: selectedBuyer.value.id,
            items: cartItems.value.map((item) => ({
                ticket_type_id: item.ticket_type_id,
                qty: item.qty,
            })),
        });

        receipt.value = data;
        toast('Venta registrada', 'success');
    } catch (err) {
        saleError.value = toError(err).message;
    } finally {
        selling.value = false;
    }
}

function newSale() {
    receipt.value = null;
    saleError.value = '';
    clearBuyer();

    for (const key of Object.keys(qty)) {
        delete qty[key];
    }

    loadTypes();
}

/*
| Comprobante imprimible. Se abre una ventana con el QR de cada entrada
| apuntando al endpoint del POS (misma sesion, misma cookie). Desde ahi el
| vendedor imprime o guarda como PDF en la PC local: no hay impresora termica.
*/
function printReceipt() {
    if (!receipt.value) {
        return;
    }

    const { order, tickets } = receipt.value;
    const origin = window.location.origin;
    const eventDate = order.starts_at
        ? new Date(order.starts_at).toLocaleString('es-AR', { dateStyle: 'long', timeStyle: 'short' })
        : '';

    const rows = tickets.map((ticket) => `
        <div class="ticket">
            <img src="${origin}${ticket.qr_url}?size=260" alt="QR ${ticket.uuid}">
            <div class="ticket-meta">
                <strong>${ticket.type_name || 'Entrada'}</strong>
                <span>Codigo: ${ticket.uuid}</span>
            </div>
        </div>`).join('');

    const html = `<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Comprobante venta #${order.id}</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 24px; color: #111; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .muted { color: #666; font-size: 13px; }
    .head { border-bottom: 2px solid #222; padding-bottom: 12px; margin-bottom: 16px; }
    .grid { display: flex; gap: 32px; flex-wrap: wrap; margin-bottom: 20px; font-size: 14px; }
    .grid div { min-width: 160px; }
    .label { display: block; color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
    .total { font-size: 18px; font-weight: 700; }
    .tickets { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
    .ticket { border: 1px dashed #999; border-radius: 8px; padding: 10px; text-align: center; page-break-inside: avoid; }
    .ticket img { width: 150px; height: 150px; }
    .ticket-meta { font-size: 11px; margin-top: 6px; word-break: break-all; }
    .ticket-meta span { display: block; color: #666; }
    @media print { body { margin: 0; } }
</style>
</head>
<body>
    <div class="head">
        <h1>Comprobante de venta</h1>
        <div class="muted">Venta #${order.id} · ${new Date(order.paid_at || order.created_at).toLocaleString('es-AR')}</div>
    </div>

    <div class="grid">
        <div><span class="label">Evento</span>${order.event_name || ''}</div>
        <div><span class="label">Fecha</span>${eventDate}</div>
        <div><span class="label">Comprador</span>${order.buyer_name || ''}${order.buyer_dni ? ` (${order.buyer_dni})` : ''}</div>
        <div><span class="label">Cantidad</span>${tickets.length}</div>
        <div><span class="label">Total</span><span class="total">${money(order.total)}</span></div>
        <div><span class="label">Vendedor</span>${order.seller_name || ''}</div>
    </div>

    <div class="tickets">${rows}</div>

    <script>window.onload = () => { window.print(); };<\/script>
</body>
</html>`;

    const win = window.open('', '_blank');

    if (!win) {
        toast('Habilita las ventanas emergentes para imprimir.', 'danger');
        return;
    }

    win.document.write(html);
    win.document.close();
}

onMounted(loadEvents);
</script>

<template>
    <div>
        <header class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
            <div>
                <h2 class="h5 fw-bold mb-0">Vender</h2>
                <p class="text-muted-2 small mb-0">Venta en efectivo. El comprobante se imprime desde esta PC.</p>
            </div>
        </header>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <EmptyState
            v-else-if="!events.length"
            icon="bi-cash-coin"
            title="Sin eventos para vender"
            hint="No tenes eventos publicados asignados. Pedile al administrador que te asigne alguno."
        />

        <div v-else class="pos">
            <section class="et-surface-raised p-3 pos__catalog">
                <div class="mb-3">
                    <label class="form-label" for="pos-event">Evento</label>
                    <select id="pos-event" v-model="eventId" class="form-select" @change="loadTypes">
                        <option value="" disabled>Elegi un evento</option>
                        <option v-for="event in events" :key="event.id" :value="event.id">
                            {{ event.name }}
                        </option>
                    </select>
                </div>

                <div v-if="loadingTypes" class="text-center py-4">
                    <div class="spinner-border spinner-border-sm" role="status"></div>
                </div>

                <EmptyState
                    v-else-if="eventId && !types.length"
                    icon="bi-ticket-perforated"
                    title="Sin tipos de entrada"
                    hint="Este evento no tiene entradas habilitadas a la venta."
                />

                <ul v-else-if="types.length" class="pos__types">
                    <li v-for="type in types" :key="type.id" class="pos__type">
                        <div class="pos__type-info">
                            <div class="fw-semibold">{{ type.name }}</div>
                            <div class="small text-faint numeric">
                                {{ money(type.price) }}
                                <template v-if="remaining(type) !== null"> · {{ remaining(type) }} disponibles</template>
                            </div>
                        </div>

                        <div class="pos__stepper">
                            <button class="btn btn-et-ghost btn-sm" :disabled="!qty[type.id]" @click="bump(type, -1)">−</button>
                            <input
                                :value="qty[type.id] || 0"
                                class="form-control form-control-sm numeric text-center"
                                style="width: 56px"
                                inputmode="numeric"
                                @input="setQty(type, $event.target.value)"
                            >
                            <button class="btn btn-et-ghost btn-sm" :disabled="qty[type.id] >= maxFor(type)" @click="bump(type, 1)">+</button>
                        </div>
                    </li>
                </ul>
            </section>

            <aside class="et-surface-raised p-3 pos__cart">
                <h3 class="section__title">Comprador</h3>

                <div v-if="selectedBuyer" class="pos__buyer-picked">
                    <div>
                        <div class="fw-semibold">{{ selectedBuyer.name }}</div>
                        <div class="small text-faint numeric">DNI {{ selectedBuyer.dni }}</div>
                    </div>
                    <button class="btn btn-et-ghost btn-sm" @click="clearBuyer">Cambiar</button>
                </div>

                <template v-else>
                    <div class="d-flex gap-2 mb-2">
                        <input
                            v-model="buyerSearch"
                            class="form-control numeric"
                            placeholder="DNI del comprador"
                            inputmode="numeric"
                            @keyup.enter="searchBuyer"
                        >
                        <button class="btn btn-et-ghost" :disabled="searchingBuyer" @click="searchBuyer">
                            <span v-if="searchingBuyer" class="spinner-border spinner-border-sm"></span>
                            <i v-else class="bi bi-search"></i>
                        </button>
                    </div>

                    <ul v-if="buyerResults.length > 1" class="pos__results">
                        <li v-for="buyer in buyerResults" :key="buyer.id">
                            <button class="pos__result" @click="selectBuyer(buyer)">
                                <span class="fw-semibold">{{ buyer.name }}</span>
                                <span class="small text-faint numeric">{{ buyer.dni }}</span>
                            </button>
                        </li>
                    </ul>

                    <div v-if="showCreateBuyer" class="pos__new-buyer">
                        <p class="small text-muted-2 mb-2">
                            No existe una cuenta con ese DNI. Cargala para venderle:
                        </p>
                        <input v-model="newBuyer.name" class="form-control mb-2" placeholder="Nombre y apellido">
                        <input v-model="newBuyer.dni" class="form-control mb-2 numeric" placeholder="DNI" inputmode="numeric">
                        <input v-model="newBuyer.phone" class="form-control mb-2" placeholder="Telefono (opcional)">
                        <input v-model="newBuyer.email" type="email" class="form-control mb-2" placeholder="Email (opcional)">
                        <button class="btn btn-et-primary btn-sm w-100" :disabled="creatingBuyer" @click="createBuyer">
                            <span v-if="creatingBuyer" class="spinner-border spinner-border-sm me-1"></span>
                            Crear comprador
                        </button>
                        <div class="form-text">La contrasena inicial es el DNI; la puede cambiar desde el portal.</div>
                    </div>
                </template>

                <p v-if="buyerError" class="small mt-2 mb-0" style="color: var(--et-danger)">
                    {{ buyerError }}
                </p>

                <hr>

                <h3 class="section__title">Detalle</h3>

                <p v-if="!cartItems.length" class="small text-muted-2">Sin entradas cargadas.</p>

                <ul v-else class="pos__lines">
                    <li v-for="item in cartItems" :key="item.ticket_type_id" class="pos__line">
                        <span>{{ item.qty }} × {{ item.name }}</span>
                        <span class="numeric">{{ money(item.subtotal) }}</span>
                    </li>
                </ul>

                <div class="pos__total">
                    <span>Total</span>
                    <strong class="numeric">{{ money(cartTotal) }}</strong>
                </div>

                <p v-if="saleError" class="small" style="color: var(--et-danger)">{{ saleError }}</p>

                <button class="btn btn-et-primary w-100" :disabled="selling || !cartCount || !selectedBuyer" @click="confirmSale">
                    <span v-if="selling" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="bi bi-cash-coin me-1"></i>
                    Cobrar efectivo
                </button>
            </aside>
        </div>

        <!-- Comprobante -->
        <div v-if="receipt" class="modal-backdrop" @click.self="newSale">
            <div class="modal-sheet modal-sheet--wide">
                <header class="modal-sheet__head">
                    <h3 class="h6 fw-bold mb-0">Venta #{{ receipt.order.id }} registrada</h3>
                    <button class="btn btn-link btn-sm p-0" @click="newSale">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>

                <div class="modal-sheet__body">
                    <div class="pos__receipt-head">
                        <div>
                            <div class="small text-faint">Comprador</div>
                            <div class="fw-semibold">{{ receipt.order.buyer_name }}</div>
                        </div>
                        <div>
                            <div class="small text-faint">Total cobrado</div>
                            <div class="fw-semibold numeric">{{ money(receipt.order.total) }}</div>
                        </div>
                        <div>
                            <div class="small text-faint">Entradas</div>
                            <div class="fw-semibold numeric">{{ receipt.tickets.length }}</div>
                        </div>
                    </div>

                    <ul class="pos__receipt-tickets">
                        <li v-for="ticket in receipt.tickets" :key="ticket.id">
                            <img :src="`/tickets-admin/pos/tickets/${ticket.id}/qr.svg?size=200`" :alt="ticket.uuid">
                            <span class="small text-faint">{{ ticket.type_name }}</span>
                        </li>
                    </ul>

                    <div class="pos__share">
                        <div class="small text-faint mb-1">Link para el comprador (abre sus entradas con el QR)</div>
                        <div class="d-flex gap-2">
                            <input
                                :value="entryLink"
                                class="form-control form-control-sm numeric"
                                readonly
                                @focus="$event.target.select()"
                            >
                            <button class="btn btn-et-ghost btn-sm" @click="copyEntryLink">
                                <i class="bi bi-clipboard me-1"></i>Copiar
                            </button>
                            <button class="btn btn-et-primary btn-sm" @click="sendByWhatsApp">
                                <i class="bi bi-whatsapp me-1"></i>WhatsApp
                            </button>
                        </div>
                    </div>

                    <footer class="modal-sheet__foot">
                        <button class="btn btn-et-ghost" @click="newSale">Nueva venta</button>
                        <button class="btn btn-et-primary" @click="printReceipt">
                            <i class="bi bi-printer me-1"></i>Imprimir comprobante
                        </button>
                    </footer>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.pos {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
    align-items: start;
}

@media (min-width: 992px) {
    .pos {
        grid-template-columns: minmax(0, 1fr) 340px;
    }
}

.pos__types {
    list-style: none;
    margin: 0;
    padding: 0;
}

.pos__type {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.65rem 0;
    border-bottom: 1px solid var(--et-border);
}

.pos__type:last-child {
    border-bottom: 0;
}

.pos__stepper {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.pos__cart {
    position: sticky;
    top: 1rem;
}

.section__title {
    font-size: 0.95rem;
    font-weight: 700;
    margin: 0 0 0.6rem;
}

.pos__buyer-picked {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.5rem 0.65rem;
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius-sm);
}

.pos__results {
    list-style: none;
    margin: 0 0 0.5rem;
    padding: 0;
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius-sm);
    overflow: hidden;
}

.pos__result {
    display: flex;
    flex-direction: column;
    width: 100%;
    padding: 0.5rem 0.65rem;
    border: 0;
    background: none;
    text-align: left;
}

.pos__result:hover {
    background: var(--et-surface-hover);
}

.pos__new-buyer {
    padding: 0.75rem;
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius-sm);
    background: var(--et-bg-elevated);
}

.pos__lines {
    list-style: none;
    margin: 0 0 0.5rem;
    padding: 0;
}

.pos__line {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.3rem 0;
    font-size: 0.875rem;
}

.pos__total {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 0.6rem 0;
    border-top: 1px solid var(--et-border);
    margin-bottom: 0.75rem;
    font-size: 1rem;
}

.modal-sheet--wide {
    max-width: 720px;
}

.pos__receipt-head {
    display: flex;
    gap: 2rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.pos__receipt-tickets {
    list-style: none;
    margin: 0 0 1rem;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 0.75rem;
    max-height: 320px;
    overflow-y: auto;
}

.pos__receipt-tickets li {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    text-align: center;
}

.pos__receipt-tickets img {
    width: 110px;
    height: 110px;
}

.pos__share {
    margin-bottom: 1rem;
}

.pos__share input {
    min-width: 0;
    flex: 1;
}
</style>

<script setup>
/**
 * Alta y edicion de un evento, con sus tipos de entrada.
 *
 * Un evento sin ningun tipo de entrada no se puede publicar: el portal lo
 * muestra igual pero sin nada que comprar. Por eso el boton de publicar avisa en
 * vez de mandar un evento vacio.
 *
 * Los tipos de entrada se editan en linea, sobre el evento ya guardado. Un
 * evento nuevo se guarda primero y despues aparecen sus tipos: asi cada tipo
 * tiene un id real y el backend no necesita un alta masiva que no existe.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import api, { toError } from '../api.js';
import { toast } from '../ui/toast.js';
import ImageFlyer from '../ui/ImageFlyer.vue';

const props = defineProps({
    id: { type: [Number, String], default: null },
});

const emit = defineEmits(['navigate']);

const isNew = computed(() => !props.id);

const form = reactive({
    name: '',
    description: '',
    starts_at: '',
    ends_at: '',
    location: '',
    capacity: '',
    cover_image: '',
    status: 'draft',
    /*
    * Access token de MercadoPago del organizador. OAuth lo guarda aca
    * (viene del code que devuelve MP). Tambien se puede pegar a mano.
    */
    mp_access_token: '',
});

const mpHasClientId = ref(false);
const mpConnected = ref(false);
const mpConnecting = ref(false);
const testingEventMp = ref(false);
const mpDisconnect = ref(false);

const types = ref([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const fieldErrors = ref({});
const uploading = ref(false);
const uploadingType = ref(false);

const canSave = computed(() => form.name.trim().length > 0 && !saving.value);

const STATUSES = [
    { value: 'draft', label: 'Borrador (no visible en el portal)' },
    { value: 'published', label: 'Publicado (visible en el portal)' },
    { value: 'closed', label: 'Cerrado' },
    { value: 'cancelled', label: 'Cancelado' },
];

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

/** Al guardar se manda ISO completo con zona, que es lo que espera el backend. */
function toIso(value) {
    if (!value) {
        return null;
    }

    return new Date(value).toISOString();
}

async function load() {
    if (isNew.value) {
        return;
    }

    loading.value = true;

    try {
        const { data } = await api.get(`tickets-admin/events/${props.id}`);

        Object.keys(form).forEach((key) => {
            let value = data[key] ?? '';

            // El backend enmascara mp_access_token como '__set__' cuando esta
            // cargado, para no exponer el secreto. Mostramos el input vacio
            // y dejamos que el badge indique que ya hay token.
            if (key === 'mp_access_token' && value === '__set__') {
                value = '';
            }

            form[key] = (key === 'starts_at' || key === 'ends_at')
                ? toLocalInput(data[key])
                : value;
        });

        // El show() devuelve mp_connected: lo usamos para pintar el badge.
        mpConnected.value = Boolean(data.mp_connected);

        types.value = (data.ticket_types || []).map((t) => ({
            ...t,
            // type="color" no acepta vacio: si el tipo viene sin pulsera, se
            // muestra negro por defecto y se persiste como negro al guardar.
            wristband_color: t.wristband_color || '#000000',
        }));
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

function invalid(field) {
    return fieldErrors.value[field]?.[0] || '';
}

async function save() {
    if (!canSave.value) {
        return;
    }

    saving.value = true;
    error.value = '';
    fieldErrors.value = {};

    const payload = {
        name: form.name.trim(),
        description: form.description || null,
        starts_at: toIso(form.starts_at),
        ends_at: toIso(form.ends_at),
        location: form.location || null,
        capacity: form.capacity ? Number(form.capacity) : null,
        cover_image: form.cover_image || null,
        status: form.status,
        /*
        | mp_access_token: el frontend arranca el form con string vacio (el
        | backend lo enmascara como "__set__" para no exponer el secreto).
        | Cuando el operador pega un token nuevo, queremos que vaya. Si lo
        | deja vacio y antes habia token, lo manda vacio y se borra.
        */
        mp_access_token: mpDisconnect.value
            ? '__disconnect__'
            : (form.mp_access_token || null),
    };

    try {
        if (isNew.value) {
            const { data } = await api.post('tickets-admin/events', payload);

            toast('Evento creado. Ahora cargale los tipos de entrada.', 'success');

            // Se reemplaza la pantalla por la edicion del evento recien creado:
            // los tipos de entrada se dan de alta sobre un id real.
            emit('navigate', 'event-edit', { id: data.id });

            return;
        }

        await api.put(`tickets-admin/events/${props.id}`, payload);

        toast('Evento guardado', 'success');
    } catch (err) {
        const formatted = toError(err);

        error.value = formatted.message;
        fieldErrors.value = formatted.errors;
    } finally {
        saving.value = false;
    }
}

async function uploadCover(event) {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    uploading.value = true;

    try {
        const body = new FormData();

        body.append('file', file);

        const { data } = await api.post('tickets-admin/events/upload', body);

        form.cover_image = data.url;
        toast('Portada subida. Guardá el evento para aplicarla.', 'success');
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        uploading.value = false;
        event.target.value = '';
    }
}

/**
 * Sube el flyer de un tipo de entrada y deja la URL en memoria. El destino es
 * el objeto del tipo (existente o nuevo): asi la misma funcion sirve para los
 * dos casos sin duplicar logica.
 *
 * El archivo va a parar a public/uploads/tickets/types/ via el endpoint del
 * backend, pero la URL NO queda asociada al tipo hasta que se guardan los
 * cambios con "Guardar" (existente) o "Agregar tipo" (nuevo). Por eso el toast
 * avisa que todavia hay que persistir.
 */
async function uploadTypeImage(event, target) {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    uploadingType.value = true;

    try {
        const body = new FormData();

        body.append('file', file);

        const { data } = await api.post('tickets-admin/ticket-types/upload', body);

        target.image_path = data.url;
        toast('Flayer cargado. Guardá los cambios para aplicarlo.', 'success');
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        uploadingType.value = false;
        event.target.value = '';
    }
}

/*
 * Conectar via OAuth: redirige a MP pasando event_id, MP devuelve al callback
 * con code, el backend intercambia el code por access_token y lo guarda en
 * tickets_events.mp_access_token.
 */
async function connectMp() {
    mpConnecting.value = true;

    try {
        const { data } = await api.get('tickets-admin/config/mp-authorize-url', {
            event_id: Number(props.id),
        });

        window.location.href = data.url;
    } catch (err) {
        toast(toError(err).message, 'danger');
        mpConnecting.value = false;
    }
}

/*
 * Llama a MP con el access_token que esta en el input. Sirve para iterar
 * sin guardar: si falla, no perdi el valor real todavia. Cuando guarda el
 * evento, el token queda persistido.
 */
async function testEventMp() {
    const token = form.mp_access_token?.trim();

    if (!token) {
        toast('Pega un Access token antes de probar.', 'warning');

        return;
    }

    testingEventMp.value = true;

    try {
        const { data } = await api.get('tickets-admin/config/mp-test', {
            params: { token },
        });

        if (data.ok) {
            toast(`Token operativo (${data.token_prefix}). MP respondio correctamente.`, 'success');
        } else {
            toast(`Fallo (${data.token_prefix}): ${data.message}`, 'danger');
        }
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        testingEventMp.value = false;
    }
}

function publish() {
    if (isNew.value) {
        toast('Guarda el evento primero', 'warning');

        return;
    }

    if (!types.value.some((t) => t.enable)) {
        toast('Agrega al menos un tipo de entrada habilitado antes de publicar.', 'warning');

        return;
    }

    form.status = 'published';
    save();
}

/* ------------------------------------------------------------------ tipos */

const blankType = () => ({
    id: null,
    name: '',
    description: '',
    image_path: '',
    price: '',
    stock: '',
    max_per_order: '',
    sale_start_at: '',
    sale_end_at: '',
    payment_mode: 'single',
    max_installments: 1,
    wristband_color: '#000000',
    wristband_label: '',
    enable: true,
    sort_order: 0,
});

const newType = ref(blankType());
const savingType = ref(false);
const typeError = ref('');

const canAddType = computed(() => newType.value.name.trim() && newType.value.price !== '');

function typePayload(type) {
    /*
    | null == null en JS es true (y tambien null == undefined). Un tipo ilimitado
    | guardado como NULL en la base llega como null al form, y Number(null) es 0:
    | el backend lo rechaza con "stock debe ser al menos 1". Se manda null en
    | cualquier caso que no sea un numero utilizable.
    */
    const toIntOrNull = (v) => (v === '' || v == null) ? null : Number(v);

    return {
        name: type.name.trim(),
        description: type.description || null,
        image_path: type.image_path || null,
        price: Number(type.price),
        stock: toIntOrNull(type.stock),
        max_per_order: toIntOrNull(type.max_per_order),
        sale_start_at: type.sale_start_at ? toIso(type.sale_start_at) : null,
        sale_end_at: type.sale_end_at ? toIso(type.sale_end_at) : null,
        payment_mode: type.payment_mode,
        max_installments: Number(type.max_installments) || 1,
        wristband_color: type.wristband_color || null,
        wristband_label: type.wristband_label || null,
        enable: Boolean(type.enable),
        sort_order: Number(type.sort_order) || 0,
    };
}

async function addType() {
    if (!canAddType.value || savingType.value) {
        return;
    }

    savingType.value = true;
    typeError.value = '';

    try {
        await api.post(`tickets-admin/events/${props.id}/ticket-types`, typePayload(newType.value));

        newType.value = blankType();
        await loadTypes();

        toast('Tipo de entrada agregado', 'success');
    } catch (err) {
        typeError.value = toError(err).message;
    } finally {
        savingType.value = false;
    }
}

async function loadTypes() {
    const { data } = await api.get(`tickets-admin/events/${props.id}/ticket-types`);

    types.value = data;
}

async function saveType(type) {
    typeError.value = '';

    try {
        await api.put(`tickets-admin/events/${props.id}/ticket-types/${type.id}`, typePayload(type));

        await loadTypes();

        toast('Tipo de entrada guardado', 'success');
    } catch (err) {
        typeError.value = toError(err).message;
    }
}

async function removeType(type) {
    typeError.value = '';

    try {
        await api.delete(`tickets-admin/events/${props.id}/ticket-types/${type.id}`);

        await loadTypes();

        toast('Tipo de entrada eliminado', 'success');
    } catch (err) {
        typeError.value = toError(err).message;
    }
}

function sold(type) {
    return Number(type.sold_count || 0);
}

function remaining(type) {
    return type.stock === null ? null : Math.max(0, Number(type.stock) - sold(type));
}

function money(value) {
    return new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        minimumFractionDigits: 0,
    }).format(Number(value || 0));
}

async function loadMpStatus() {
    if (isNew.value) {
        return;
    }

    try {
        const { data } = await api.get('tickets-admin/config/mp-status', {
            params: { event_id: Number(props.id) },
        });

        mpHasClientId.value = Boolean(data.has_client_id);
    } catch {
        // Si falla, dejamos el estado en "no OAuth disponible".
    }
}

onMounted(async () => {
    await load();
    await loadMpStatus();
});
</script>

<template>
    <div>
        <button class="btn btn-link btn-sm p-0 mb-2" @click="emit('navigate', 'events')">
            <i class="bi bi-arrow-left me-1"></i>Eventos
        </button>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <form v-else class="row g-3" @submit.prevent="save">
            <div class="col-12 col-lg-7">
                <section class="et-surface-raised p-3 mb-3">
                    <h2 class="h6 fw-bold mb-3">{{ isNew ? 'Nuevo evento' : 'Datos del evento' }}</h2>

                    <div class="mb-3">
                        <label class="form-label" for="name">Nombre</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': invalid('name') }"
                        >
                        <div v-if="invalid('name')" class="invalid-feedback">{{ invalid('name') }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="description">Descripcion</label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="4"
                            class="form-control"
                        ></textarea>
                        <div class="form-text">Se muestra en el detalle del evento.</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label" for="starts">Comienza</label>
                            <input id="starts" v-model="form.starts_at" type="datetime-local" class="form-control">
                        </div>

                        <div class="col-12 col-sm-6">
                            <label class="form-label" for="ends">Termina</label>
                            <input id="ends" v-model="form.ends_at" type="datetime-local" class="form-control">
                        </div>

                        <div class="col-12 col-sm-7">
                            <label class="form-label" for="location">Lugar</label>
                            <input id="location" v-model="form.location" type="text" class="form-control">
                        </div>

                        <div class="col-12 col-sm-5">
                            <label class="form-label" for="capacity">Capacidad</label>
                            <input
                                id="capacity"
                                v-model="form.capacity"
                                type="number"
                                min="1"
                                class="form-control"
                            >
                        </div>
                    </div>
                </section>

                <!-- Tipos de entrada -->
                <section v-if="!isNew" class="et-surface-raised p-3">
                    <h2 class="h6 fw-bold mb-3">Tipos de entrada</h2>

                    <p v-if="typeError" class="small" style="color: var(--et-danger)">
                        <i class="bi bi-exclamation-triangle me-1"></i>{{ typeError }}
                    </p>

                    <div v-if="!types.length" class="text-muted-2 small mb-3">
                        Todavia no hay tipos de entrada. Agrega al menos uno.
                    </div>

                    <article v-for="type in types" :key="type.id" class="type">
                        <div class="type__head">
                            <input
                                v-model="type.name"
                                type="text"
                                class="form-control form-control-sm fw-semibold"
                                aria-label="Nombre"
                            >

                            <div class="form-check form-switch mb-0">
                                <input
                                    :id="`enable-${type.id}`"
                                    v-model="type.enable"
                                    class="form-check-input"
                                    type="checkbox"
                                >
                                <label class="form-check-label small" :for="`enable-${type.id}`">
                                    Visible
                                </label>
                            </div>
                        </div>

                        <div class="type__grid">
                            <div>
                                <label class="form-label small">Precio</label>
                                <input
                                    v-model="type.price"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="form-control form-control-sm numeric"
                                >
                            </div>

                            <div>
                                <label class="form-label small">Stock</label>
                                <input
                                    v-model="type.stock"
                                    type="number"
                                    min="1"
                                    class="form-control form-control-sm numeric"
                                    placeholder="Ilimitado"
                                >
                            </div>

                            <div>
                                <label class="form-label small">Vendidas</label>
                                <input
                                    :value="sold(type)"
                                    type="text"
                                    class="form-control form-control-sm numeric"
                                    disabled
                                >
                            </div>

                            <div>
                                <label class="form-label small">Max por compra</label>
                                <input
                                    v-model="type.max_per_order"
                                    type="number"
                                    min="1"
                                    max="100"
                                    class="form-control form-control-sm numeric"
                                >
                            </div>

                            <div>
                                <label class="form-label small">Pago</label>
                                <select v-model="type.payment_mode" class="form-select form-select-sm">
                                    <option value="single">Solo una cuota</option>
                                    <option value="installments">Solo cuotas</option>
                                    <option value="both">Las dos</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label small">Max cuotas</label>
                                <input
                                    v-model="type.max_installments"
                                    type="number"
                                    min="1"
                                    max="24"
                                    class="form-control form-control-sm numeric"
                                    :disabled="type.payment_mode === 'single'"
                                >
                            </div>

                            <div>
                                <label class="form-label small">Color pulsera</label>
                                <input
                                    v-model="type.wristband_color"
                                    type="color"
                                    class="form-control form-control-color form-control-sm"
                                >
                            </div>

                            <div>
                                <label class="form-label small">Texto pulsera</label>
                                <input
                                    v-model="type.wristband_label"
                                    type="text"
                                    class="form-control form-control-sm"
                                    placeholder="General"
                                >
                            </div>
                        </div>

                        <div class="type__flyer">
                            <div class="type__flyer-img">
                                <ImageFlyer
                                    v-if="type.image_path"
                                    :src="type.image_path"
                                    alt="Flayer del tipo de entrada"
                                    thumb-class="type__flyer-thumb"
                                />
                            </div>

                            <div class="type__flyer-input">
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="form-control form-control-sm"
                                    :disabled="uploadingType"
                                    @change="uploadTypeImage($event, type)"
                                >
                                <input
                                    v-model="type.image_path"
                                    type="text"
                                    class="form-control form-control-sm mt-1"
                                    placeholder="o pegá la URL de la imagen"
                                >
                            </div>
                        </div>

                        <div class="type__foot">
                            <span class="small text-faint numeric">
                                <template v-if="remaining(type) === null">Stock ilimitado</template>
                                <template v-else>Quedan {{ remaining(type) }}</template>
                            </span>

                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-et-ghost btn-sm" @click="saveType(type)">
                                    Guardar
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-et-ghost btn-sm btn-danger-soft"
                                    :disabled="sold(type) > 0"
                                    :title="sold(type) > 0 ? 'No se puede borrar un tipo con entradas vendidas' : 'Eliminar'"
                                    @click="removeType(type)"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </article>

                    <div class="type type--new">
                        <div class="type__head">
                            <input
                                v-model="newType.name"
                                type="text"
                                class="form-control form-control-sm fw-semibold"
                                placeholder="Nuevo tipo de entrada"
                                aria-label="Nombre del nuevo tipo"
                            >
                        </div>

                        <div class="type__grid">
                            <div>
                                <label class="form-label small">Precio</label>
                                <input
                                    v-model="newType.price"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="form-control form-control-sm numeric"
                                >
                            </div>

                            <div>
                                <label class="form-label small">Stock</label>
                                <input
                                    v-model="newType.stock"
                                    type="number"
                                    min="1"
                                    class="form-control form-control-sm numeric"
                                    placeholder="Ilimitado"
                                >
                            </div>

                            <div>
                                <label class="form-label small">Max por compra</label>
                                <input
                                    v-model="newType.max_per_order"
                                    type="number"
                                    min="1"
                                    max="100"
                                    class="form-control form-control-sm numeric"
                                >
                            </div>

                            <div>
                                <label class="form-label small">Pago</label>
                                <select v-model="newType.payment_mode" class="form-select form-select-sm">
                                    <option value="single">Solo una cuota</option>
                                    <option value="installments">Solo cuotas</option>
                                    <option value="both">Las dos</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label small">Max cuotas</label>
                                <input
                                    v-model="newType.max_installments"
                                    type="number"
                                    min="1"
                                    max="24"
                                    class="form-control form-control-sm numeric"
                                >
                            </div>
                        </div>

                        <div class="type__flyer">
                            <div class="type__flyer-img">
                                <ImageFlyer
                                    v-if="newType.image_path"
                                    :src="newType.image_path"
                                    alt="Flayer del tipo de entrada"
                                    thumb-class="type__flyer-thumb"
                                />
                            </div>

                            <div class="type__flyer-input">
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="form-control form-control-sm"
                                    :disabled="uploadingType"
                                    @change="uploadTypeImage($event, newType)"
                                >
                                <input
                                    v-model="newType.image_path"
                                    type="text"
                                    class="form-control form-control-sm mt-1"
                                    placeholder="o pegá la URL de la imagen"
                                >
                            </div>
                        </div>

                        <div class="type__foot">
                            <span />
                            <button
                                type="button"
                                class="btn btn-et-primary btn-sm"
                                :disabled="!canAddType || savingType"
                                @click="addType"
                            >
                                <span v-if="savingType" class="spinner-border spinner-border-sm me-1"></span>
                                Agregar tipo
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-12 col-lg-5">
                <div class="sticky-side">
                    <section class="et-surface-raised p-3 mb-3">
                        <h2 class="h6 fw-bold mb-3">Portada</h2>

                        <ImageFlyer
                            v-if="form.cover_image"
                            :src="form.cover_image"
                            alt="Portada del evento"
                            thumb-class="cover mb-2"
                        />

                        <input
                            type="file"
                            accept="image/*"
                            class="form-control"
                            :disabled="uploading"
                            @change="uploadCover"
                        >

                        <button
                            v-if="form.cover_image"
                            type="button"
                            class="btn btn-link btn-sm p-0 mt-2"
                            @click="form.cover_image = ''"
                        >
                            Quitar la portada
                        </button>
                    </section>

                    <section v-if="!isNew" class="et-surface-raised p-3 mb-3">
                        <h2 class="h6 fw-bold mb-3">MercadoPago</h2>

                        <p class="small text-muted-2 mb-2">
                            Cada evento cobra con su propia cuenta de MP.
                            Conectala via el botón de OAuth, o pega el
                            <strong>Access token</strong> a mano.
                        </p>

                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span
                                v-if="mpConnected"
                                class="et-badge et-badge--success"
                            >
                                Conectado
                            </span>
                            <span v-else class="et-badge et-badge--warning">
                                Sin token
                            </span>

                            <button
                                v-if="!mpConnected && mpHasClientId"
                                type="button"
                                class="btn btn-et-primary btn-sm ms-auto"
                                :disabled="mpConnecting"
                                @click="connectMp"
                            >
                                <span
                                    v-if="mpConnecting"
                                    class="spinner-border spinner-border-sm me-1"
                                ></span>
                                Conectar con MP
                            </button>

                            <button
                                v-if="mpConnected"
                                type="button"
                                class="btn btn-et-ghost btn-sm ms-auto"
                                :disabled="testingEventMp"
                                @click="testEventMp"
                            >
                                <span
                                    v-if="testingEventMp"
                                    class="spinner-border spinner-border-sm me-1"
                                ></span>
                                Probar
                            </button>
                        </div>

                        <input
                            v-model="form.mp_access_token"
                            type="text"
                            class="form-control form-control-sm"
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="form.mp_access_token ? 'Pegá uno nuevo para reemplazar' : 'APP_USR-... o TEST-...'"
                        >

                        <p class="form-text mb-0 mt-2">
                            Lo sacas de la cuenta de MP del organizador &raquo;
                            <strong>Tus integraciones</strong> &raquo; <strong>Credenciales</strong>.
                            Empieza con <code>APP_USR-</code> (produccion) o <code>TEST-</code> (pruebas).
                            Queda guardado al apretar <strong>Guardar cambios</strong> del evento.
                        </p>

                        <div v-if="mpConnected" class="form-check mt-2">
                                <input
                                    id="mp-disconnect"
                                    v-model="mpDisconnect"
                                    class="form-check-input"
                                    type="checkbox"
                                >
                                <label class="form-check-label small" for="mp-disconnect">
                                    Desconectar MercadoPago (borra el token guardado)
                                </label>
                            </div>

                        <p
                            v-if="!mpConnected && !mpHasClientId"
                            class="small mt-2 mb-0"
                            style="color: var(--et-warning, #b8860b)"
                        >
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            La app MP no esta registrada en el servidor
                            (<code>MP_CLIENT_ID</code> en <code>.env</code>), asi que el boton
                            "Conectar con MP" no funciona. Pega el token a mano.
                        </p>

                        <p
                            v-else-if="!mpConnected"
                            class="small mt-2 mb-0"
                            style="color: var(--et-warning, #b8860b)"
                        >
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Mientras este vacio, los compradores no podran pagar este evento.
                        </p>
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

                        <div class="d-flex flex-column gap-2">
                            <button class="btn btn-et-primary" type="submit" :disabled="!canSave">
                                <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
                                {{ isNew ? 'Crear evento' : 'Guardar cambios' }}
                            </button>

                            <button
                                v-if="!isNew && form.status !== 'published'"
                                type="button"
                                class="btn btn-et-ghost"
                                @click="publish"
                            >
                                <i class="bi bi-eye me-1"></i>Publicar
                            </button>
                        </div>

                        <p v-if="!isNew && !types.some((t) => t.enable)" class="small text-faint mt-3 mb-0">
                            Sin tipos de entrada habilitados, el evento no se puede publicar.
                        </p>
                    </section>
                </div>
            </div>
        </form>
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

/*
| :deep() para que las clases thumb-class aplicadas al <button> raiz del
| ImageFlyer matcheen desde aca (ver nota en EventDetailView.vue).
| El padre es el <section> que envuelve la portada en la sidebar.
*/
.et-surface-raised :deep(.cover) {
    width: 100%;
    aspect-ratio: 16 / 9;
    border-radius: var(--et-radius-sm);
}

.type {
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius-sm);
    padding: 0.85rem;
    margin-bottom: 0.75rem;
}

.type--new {
    border-style: dashed;
    background: var(--et-surface);
}

.type__head {
    display: flex;
    gap: 0.75rem;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
}

.type__head .form-control {
    max-width: 260px;
}

.type__grid {
    display: grid;
    gap: 0.6rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

@media (min-width: 576px) {
    .type__grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

.type__foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-top: 0.85rem;
}

.type__flyer {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 0.75rem;
    align-items: center;
    margin-top: 0.85rem;
    padding-top: 0.85rem;
    border-top: 1px dashed var(--et-border);
}

.type__flyer-img :deep(.type__flyer-thumb) {
    width: 72px;
    height: 50px;
    border-radius: var(--et-radius-sm);
    border: 1px solid var(--et-border);
}
</style>
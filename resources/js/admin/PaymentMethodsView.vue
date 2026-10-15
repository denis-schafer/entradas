<script setup>
/**
 * "Medios de pago" (admin).
 *
 * Dos niveles:
 *   1. Config GLOBAL del proveedor (la cuenta: enabled + credenciales).
 *   2. Config por evento: se elige el evento en un <select> y se muestran sus
 *      ajustes particulares (habilitado, token OAuth del evento, etc.).
 *
 * Multipago: la cuenta puede ser global o propia de un evento (bersacode,
 * usuario y contraseña). En blanco, el evento usa la cuenta global. El webhook
 * SIEMPRE es único/global (una sola URL y key para toda la instalación).
 *
 * Las credenciales viajan enmascaradas: el backend devuelve la mascara donde
 * hay un secreto guardado; al guardar, ese valor se interpreta como "no tocar".
 */
import { ref, onMounted } from 'vue';
import api, { toError } from '../api.js';
import { toast } from '../ui/toast.js';

const BULLET = '\u2022';

defineProps({
    id: { type: [Number, String], default: null },
});

const loading = ref(false);
const methods = ref([]);
const open = ref({});
const selectedEvent = ref({});

const FIELDS = {
    mercadopago: {
        global: [
            { key: 'client_id', label: 'Client ID (credenciales de desarrollo)', secret: false },
            { key: 'client_secret', label: 'Client Secret (credenciales de desarrollo)', secret: true },
            { key: 'platform_access_token', label: 'Access token de mi cuenta (se usa si el evento no tiene token propio)', secret: true },
        ],
        eventTitle: 'Configuración por evento',
        eventHint: 'Elegí un evento para cargar su token OAuth, probarlo, o dejarlo en la cuenta de la plataforma.',
        eventFields: [
            { key: 'access_token', label: 'Access token OAuth del evento (opcional)', secret: true, placeholder: 'APP_USR-... o TEST-... (vacío = cuenta de la plataforma)' },
        ],
    },
    multipago: {
        global: [
            { key: 'bersacode', label: 'Bersa code (comercio)', secret: false },
            { key: 'username', label: 'Usuario API', secret: false },
            { key: 'password', label: 'Contraseña API', secret: true },
            { key: 'header', label: 'Encabezado del QR (lo entrega Multipago)', secret: true },
            { key: 'seeder', label: 'Semilla del QR (la entrega Multipago)', secret: true },
        ],
        eventTitle: 'Cuenta y modalidades por evento',
        eventHint: 'Si el cliente tiene su propia cuenta de Multipago, cargá su bersacode, usuario y contraseña, y el encabezado/semilla de su QR. En blanco, el evento usa los globales. El webhook es único para toda la instalación.',
        eventFields: [
            { key: 'bersacode', label: 'Bersa code (comercio) del evento', secret: true, placeholder: 'Vacío = bersacode global' },
            { key: 'username', label: 'Usuario API del evento', secret: true, placeholder: 'Vacío = usuario global' },
            { key: 'password', label: 'Contraseña API del evento', secret: true, placeholder: 'Vacío = contraseña global' },
            { key: 'header', label: 'Encabezado del QR del evento', secret: true, placeholder: 'Vacío = encabezado global' },
            { key: 'seeder', label: 'Semilla del QR del evento', secret: true, placeholder: 'Vacío = semilla global' },
        ],
    },
};

// Estado de edición.
const enabledDraft = ref({});      // code -> bool
const globalDrafts = ref({});      // code -> { key: value }
const clearedGlobal = ref({});     // code -> [key]
const eventDrafts = ref({});       // code -> { eventId: { key: value } }
const eventEnabled = ref({});      // code -> { eventId: bool }
const eventQr = ref({});           // code -> { eventId: bool }  (multipago)
const eventButton = ref({});       // code -> { eventId: bool }  (multipago)
const eventClearedKeys = ref({});  // code -> { eventId: [key] }
const saving = ref({});
const savingEvent = ref({});
const testing = ref({});
const validating = ref(false);
const validateFrom = ref('');
const validateTo = ref('');
const validateEvent = ref(null);   // null = cuenta global
const copied = ref('');
const qrSecret = ref(false);       // firma de los QR (config global)

function isMask(value) {
    return typeof value === 'string' && value.startsWith(BULLET);
}

function maskText() {
    return BULLET.repeat(8);
}

async function load() {
    loading.value = true;

    try {
        const { data } = await api.get('tickets-admin/payment-methods');

        methods.value = data.methods || [];

        methods.value.forEach((m) => {
            enabledDraft.value[m.code] = !!m.enabled;
            clearedGlobal.value[m.code] = [];

            const drafts = {};

            (FIELDS[m.code]?.global || []).forEach((f) => {
                const value = m.config?.[f.key];

                if (f.type === 'bool') {
                    drafts[f.key] = value === undefined || value === null
                        ? true
                        : !(value === false || value === 0 || value === '0' || value === 'false');

                    return;
                }

                drafts[f.key] = f.secret && isMask(value) ? '' : (value ?? '');
            });

            globalDrafts.value[m.code] = drafts;

            eventDrafts.value[m.code] = {};
            eventEnabled.value[m.code] = {};
            eventQr.value[m.code] = {};
            eventButton.value[m.code] = {};
            eventClearedKeys.value[m.code] = {};

            (m.events || []).forEach((ev) => {
                const drafts = {};

                (FIELDS[m.code]?.eventFields || []).forEach((f) => {
                    const value = ev.config?.[f.key];

                    drafts[f.key] = f.secret && isMask(value) ? '' : (value ?? '');
                });

                eventDrafts.value[m.code][ev.event_id] = drafts;
                eventEnabled.value[m.code][ev.event_id] = !!ev.enabled;
                eventQr.value[m.code][ev.event_id] = ev.config?.qr_enabled === undefined
                    ? true
                    : !!ev.config.qr_enabled;
                eventButton.value[m.code][ev.event_id] = ev.config?.button_enabled === undefined
                    ? true
                    : !!ev.config.button_enabled;
            });

            // Con un solo evento configurado el selector sobra: se elige solo
            // para que el bloque de configuracion quede visible sin un paso
            // extra que no aporta nada.
            selectedEvent.value[m.code] = selectedEvent.value[m.code]
                ?? ((m.events || []).length === 1 ? m.events[0].event_id : null);
            open.value[m.code] = open.value[m.code] ?? false;
        });
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        loading.value = false;
    }
}

function toggleOpen(code) {
    open.value[code] = !open.value[code];
}

function methodProps(code) {
    return methods.value.find((m) => m.code === code) || {};
}

function eventsFor(code) {
    return methodProps(code).events || [];
}

function selectedEventObj(code) {
    const id = selectedEvent.value[code];

    return eventsFor(code).find((e) => String(e.event_id) === String(id)) || null;
}

function hasGlobalSecret(method, key) {
    return isMask(method.config?.[key]);
}

function eventFieldsFor(code) {
    return FIELDS[code]?.eventFields || [];
}

function eventHasSecret(code, eventId, key) {
    const ev = eventsFor(code).find((e) => String(e.event_id) === String(eventId));

    return isMask(ev?.config?.[key]);
}

async function saveGlobal(code) {
    saving.value[code] = true;

    try {
        await api.put(`tickets-admin/payment-methods/${code}`, {
            enabled: enabledDraft.value[code],
            config: globalDrafts.value[code],
            cleared_keys: clearedGlobal.value[code] || [],
        });

        toast('Config guardada', 'success');
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        saving.value[code] = false;
    }
}

function toggleGlobalEnabled(code) {
    enabledDraft.value[code] = !enabledDraft.value[code];
    saveGlobal(code);
}

function clearGlobalSecret(code, key) {
    clearedGlobal.value[code] = [...(clearedGlobal.value[code] || []), key];
    globalDrafts.value[code][key] = '';
}

async function saveEvent(code) {
    const eventId = selectedEvent.value[code];

    if (!eventId) {
        return;
    }

    const key = `${code}:${eventId}`;

    // Credenciales por evento (MP: token OAuth; Multipago: cuenta propia). El
    // backend ignora los vacios y la mascara, que significan "usar el global".
    const config = {};

    eventFieldsFor(code).forEach((f) => {
        config[f.key] = eventDrafts.value[code]?.[eventId]?.[f.key] ?? '';
    });

    // Multipago elige ademas, por evento, que modalidades se ofrecen.
    if (code === 'multipago') {
        config.qr_enabled = !!eventQr.value[code]?.[eventId];
        config.button_enabled = !!eventButton.value[code]?.[eventId];
    }

    savingEvent.value[key] = true;

    try {
        await api.put(`tickets-admin/payment-methods/${code}/events/${eventId}`, {
            enabled: eventEnabled.value[code][eventId],
            config,
            cleared_keys: eventClearedKeys.value[code]?.[eventId] || [],
        });

        toast('Configuración del evento guardada', 'success');
        eventClearedKeys.value[code][eventId] = [];
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        savingEvent.value[key] = false;
    }
}

function clearEventSecret(code, eventId, key) {
    eventDrafts.value[code][eventId][key] = '';
    eventClearedKeys.value[code][eventId] = [
        ...(eventClearedKeys.value[code][eventId] || []),
        key,
    ];
}

async function testMethod(code, eventId = null, token = null) {
    const key = eventId ? `${code}:${eventId}` : code;

    testing.value[key] = true;

    try {
        const payload = {};

        if (eventId) {
            payload.event_id = eventId;
        }

        if (token) {
            payload.access_token = token;
        }

        const { data } = await api.post(`tickets-admin/payment-methods/${code}/test`, payload);

        toast(data.message, data.ok ? 'success' : 'danger');
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        testing.value[key] = false;
    }
}

/* Token OAuth tipeado (no guardado) para el evento seleccionado de MP, o null. */
function eventTestToken(code) {
    const id = selectedEvent.value[code];
    const draft = eventDrafts.value[code]?.[id]?.access_token || '';

    return draft && !isMask(draft) ? draft : null;
}

async function validatePayments() {
    validating.value = true;

    try {
        const { data } = await api.post('tickets-admin/payment-methods/multipago/validate', {
            desde: validateFrom.value || null,
            hasta: validateTo.value || null,
            event_id: validateEvent.value || null,
        });

        const s = data.summary;
        toast(
            `Validación: ${s.insertadas} insertadas · ${s.duplicadas} duplicadas · ${s.sin_procesar} sin procesar (${s.total} total).`,
            s.insertadas > 0 ? 'success' : 'info',
        );
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        validating.value = false;
    }
}

async function regenerateWebhook(code) {
    try {
        const { data } = await api.post(`tickets-admin/payment-methods/${code}/webhook-key/regenerate`);

        methodProps(code).webhook_url = data.url;
        toast('Nueva URL de webhook generada. Actualizala en Multipago.', 'success');
    } catch (err) {
        toast(toError(err).message, 'danger');
    }
}

async function copyToClipboard(text, label) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = label;
        setTimeout(() => { copied.value = ''; }, 1500);
    } catch {
        toast('No se pudo copiar', 'warning');
    }
}

function connectMpEvent(eventId) {
    window.location.href = `/tickets-admin/config/mp-authorize-url?event_id=${eventId}`;
}

/*
 * La firma de los QR no es de un proveedor en particular: es una clave global
 * del sistema. Vive aca porque es una credencial de cobro y el backend la
 * enmascara como "__set__" igual que el resto.
 */
async function loadQrSecret() {
    try {
        const { data } = await api.get('tickets-admin/config');

        qrSecret.value = Boolean(data.find((c) => c.name === 'qr_secret')?.value);
    } catch {
        // Si falla, queda en "sin configurar".
    }
}

onMounted(() => {
    load();
    loadQrSecret();
});
</script>

<template>
    <div>
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h4 mb-1">Medios de pago</h2>
                <p class="text-muted-2 small mb-0">
                    Credenciales de cada proveedor y con qué medios cobra cada evento.
                </p>
            </div>
        </div>

        <div v-if="loading" class="d-flex justify-content-center py-5">
            <div class="spinner-border text-primary"></div>
        </div>

        <div v-else class="d-flex flex-column gap-3">
            <section class="et-surface-raised p-3">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="fw-semibold small">Firma de los QR</span>
                    <span
                        class="et-badge"
                        :class="qrSecret ? 'et-badge--success' : 'et-badge--warning'"
                    >
                        <i
                            class="bi me-1"
                            :class="qrSecret ? 'bi-check-circle-fill' : 'bi-dash-circle'"
                        ></i>
                        {{ qrSecret ? 'Configurada' : 'Sin configurar' }}
                    </span>
                </div>
                <p class="form-text small mb-0 mt-2">
                    Clave con la que se firman los QR. La genera el sistema en el
                    primer QR emitido, por eso puede figurar "sin configurar" hasta
                    que exista una orden pagada.
                </p>
            </section>

            <section
                v-for="method in methods"
                :key="method.code"
                class="et-surface-raised"
            >
                <!-- Cabecera del acordeón -->
                <button
                    type="button"
                    class="payment-method-head"
                    @click="toggleOpen(method.code)"
                >
                    <i class="bi" :class="method.code === 'mercadopago' ? 'bi-bank' : 'bi-qr-code-scan'"></i>
                    <span class="fw-bold">{{ method.name }}</span>

                    <span
                        class="et-badge"
                        :class="enabledDraft[method.code] ? 'et-badge--success' : 'et-badge--warning'"
                        @click.stop
                    >
                        <label class="form-check form-switch form-switch-sm mb-0">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                :checked="enabledDraft[method.code]"
                                @change="toggleGlobalEnabled(method.code)"
                            >
                        </label>
                        {{ enabledDraft[method.code] ? 'Habilitado' : 'Deshabilitado' }}
                    </span>

                    <span class="ms-auto">
                        <i
                            class="bi bi-chevron-down ms-2"
                            :class="{ 'is-open': open[method.code] }"
                        ></i>
                    </span>
                </button>

                <div v-if="open[method.code]" class="p-3 border-top">
                    <!-- Config global del proveedor -->
                    <h3 class="h6 fw-bold mb-2">Cuenta del proveedor (global)</h3>
                    <div class="row g-2 align-items-end mb-2">
                        <div
                            v-for="field in FIELDS[method.code]?.global || []"
                            :key="field.key"
                            :class="field.type === 'bool' ? 'col-md-3' : 'col-md-4'"
                        >
                            <template v-if="field.type === 'bool'">
                                <label class="form-label small mb-1 d-block">{{ field.label }}</label>
                                <div class="form-check form-switch">
                                    <input
                                        :id="`${method.code}-${field.key}`"
                                        class="form-check-input"
                                        type="checkbox"
                                        v-model="globalDrafts[method.code][field.key]"
                                    >
                                    <span class="small ms-1">
                                        {{ globalDrafts[method.code][field.key] ? 'Sí' : 'No' }}
                                    </span>
                                </div>
                            </template>
                            <template v-else>
                                <label class="form-label small mb-1" :for="`${method.code}-${field.key}`">
                                    {{ field.label }}
                                    <button
                                        v-if="hasGlobalSecret(method, field.key)"
                                        type="button"
                                        class="btn btn-link btn-sm p-0 ms-2 text-danger"
                                        @click="clearGlobalSecret(method.code, field.key)"
                                    >
                                        Quitar
                                    </button>
                                </label>
                                <input
                                    :id="`${method.code}-${field.key}`"
                                    v-model="globalDrafts[method.code][field.key]"
                                    type="text"
                                    class="form-control form-control-sm"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :placeholder="hasGlobalSecret(method, field.key)
                                        ? `${maskText()} (guardado)`
                                        : field.key"
                                >
                                <div
                                    v-if="hasGlobalSecret(method, field.key)"
                                    class="form-text small text-success"
                                >
                                    <i class="bi bi-check-circle-fill me-1"></i>
                                    Secreto guardado. Dejalo vacío para no cambiarlo.
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button
                            type="button"
                            class="btn btn-et-primary btn-sm"
                            :disabled="saving[method.code]"
                            @click="saveGlobal(method.code)"
                        >
                            <span
                                v-if="saving[method.code]"
                                class="spinner-border spinner-border-sm me-1"
                            ></span>
                            Guardar config
                        </button>
                        <button
                            type="button"
                            class="btn btn-et-ghost btn-sm"
                            :disabled="testing[method.code]"
                            @click="testMethod(method.code)"
                        >
                            <span
                                v-if="testing[method.code]"
                                class="spinner-border spinner-border-sm me-1"
                            ></span>
                            Probar conexión
                        </button>
                    </div>

                    <!-- Webhook único (Multipago) -->
                    <template v-if="method.webhook_url !== null && method.webhook_url !== undefined">
                        <hr class="my-3">
                        <h3 class="h6 fw-bold mb-1">Webhook (único)</h3>
                        <p class="small text-muted-2 mb-2">
                            Una sola URL para todos los eventos. Copiala en la configuración de {{ method.name }}.
                        </p>
                        <div v-if="method.webhook_url" class="input-group input-group-sm" style="max-width: 620px">
                            <input
                                :value="method.webhook_url"
                                type="text"
                                readonly
                                class="form-control form-control-sm font-monospace"
                                @focus="$event.target.select()"
                            >
                            <button
                                type="button"
                                class="btn btn-outline-secondary btn-sm"
                                @click="copyToClipboard(method.webhook_url, `wh-${method.code}`)"
                            >
                                <i class="bi" :class="copied === `wh-${method.code}` ? 'bi-check' : 'bi-clipboard'"></i>
                            </button>
                            <button
                                type="button"
                                class="btn btn-outline-danger btn-sm"
                                title="Rotar la key (invalida la URL anterior)"
                                @click="regenerateWebhook(method.code)"
                            >
                                <i class="bi bi-arrow-repeat"></i>
                            </button>
                        </div>
                        <span v-else class="small text-muted-2">Se generará al guardar la config.</span>
                    </template>

                    <!-- Validar pagos (Multipago) -->
                    <template v-if="method.code === 'multipago'">
                        <hr class="my-3">
                        <h3 class="h6 fw-bold mb-1">Validar pagos</h3>
                        <p class="small text-muted-2 mb-2">
                            Consulta los cobros del período y acredita los pendientes (mismo camino que el webhook).
                            Elegí la cuenta global o la de un evento con cuenta propia.
                        </p>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <select
                                class="form-select form-select-sm"
                                style="width: auto"
                                v-model="validateEvent"
                            >
                                <option :value="null">Cuenta global</option>
                                <option
                                    v-for="event in eventsFor('multipago')"
                                    :key="event.event_id"
                                    :value="event.event_id"
                                >
                                    {{ event.event_name }}
                                </option>
                            </select>
                            <button
                                type="button"
                                class="btn btn-et-primary btn-sm"
                                :disabled="validating"
                                @click="validatePayments"
                            >
                                <span
                                    v-if="validating"
                                    class="spinner-border spinner-border-sm me-1"
                                ></span>
                                <i class="bi bi-arrow-repeat me-1"></i>
                                Validar pagos
                            </button>
                            <input type="date" class="form-control form-control-sm" style="width: auto" v-model="validateFrom">
                            <span class="small">a</span>
                            <input type="date" class="form-control form-control-sm" style="width: auto" v-model="validateTo">
                        </div>
                    </template>

                    <hr class="my-3">

                    <!-- Config por evento -->
                    <h3 class="h6 fw-bold mb-1">{{ FIELDS[method.code]?.eventTitle }}</h3>
                    <p class="small text-muted-2 mb-2">{{ FIELDS[method.code]?.eventHint }}</p>

                    <div v-if="!eventsFor(method.code).length" class="small text-muted-2">
                        No hay eventos cargados.
                    </div>

                    <template v-else>
                        <div v-if="eventsFor(method.code).length > 1" class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Evento</label>
                                <select
                                    class="form-select form-select-sm"
                                    v-model="selectedEvent[method.code]"
                                >
                                    <option :value="null" disabled>Seleccioná un evento…</option>
                                    <option
                                        v-for="event in eventsFor(method.code)"
                                        :key="event.event_id"
                                        :value="event.event_id"
                                    >
                                        {{ event.event_name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div
                            v-if="selectedEventObj(method.code)"
                            class="border rounded p-3"
                        >
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="fw-semibold">{{ selectedEventObj(method.code).event_name }}</span>
                                <label class="form-check form-switch mb-0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        v-model="eventEnabled[method.code][selectedEvent[method.code]]"
                                    >
                                    <span class="ms-1 small">
                                        {{ eventEnabled[method.code][selectedEvent[method.code]] ? 'Habilitado' : 'Deshabilitado' }}
                                    </span>
                                </label>
                            </div>

                            <!-- Credenciales por evento (MP: token OAuth; Multipago: cuenta propia) -->
                            <template v-for="field in eventFieldsFor(method.code)" :key="field.key">
                                <label class="form-label small mb-1 mt-2">{{ field.label }}</label>
                                <div class="input-group input-group-sm">
                                    <input
                                        v-model="eventDrafts[method.code][selectedEvent[method.code]][field.key]"
                                        type="text"
                                        class="form-control form-control-sm"
                                        autocomplete="off"
                                        spellcheck="false"
                                        :placeholder="eventHasSecret(method.code, selectedEvent[method.code], field.key)
                                            ? `${maskText()} (guardado)`
                                            : field.placeholder"
                                    >
                                    <button
                                        v-if="eventHasSecret(method.code, selectedEvent[method.code], field.key)"
                                        type="button"
                                        class="btn btn-outline-danger btn-sm"
                                        title="Quitar credencial del evento (vuelve a usar la global)"
                                        @click="clearEventSecret(method.code, selectedEvent[method.code], field.key)"
                                    >
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </template>

                            <div v-if="method.code === 'mercadopago'" class="form-text small">
                                Si lo dejás vacío, el evento usa el access token de la plataforma.
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm p-0 ms-1"
                                    @click="connectMpEvent(selectedEvent[method.code])"
                                >
                                    Conectar vía OAuth
                                </button>
                            </div>

                            <!-- Multipago: modalidades por evento -->
                            <template v-if="method.code === 'multipago'">
                                <hr class="my-3">
                                <p class="small text-muted-2 mb-2">Modalidades ofrecidas en este evento:</p>
                                <label class="form-check form-switch mb-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        v-model="eventQr[method.code][selectedEvent[method.code]]"
                                    >
                                    <span class="ms-1 small">Ofrecer QR</span>
                                </label>
                                <label class="form-check form-switch mb-0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        v-model="eventButton[method.code][selectedEvent[method.code]]"
                                    >
                                    <span class="ms-1 small">Ofrecer botón de pago (código de barras)</span>
                                </label>
                                <p class="form-text small mt-2 mb-0">
                                    El webhook de Multipago es único para toda la instalación y no cambia por evento.
                                </p>
                            </template>

                            <div class="d-flex gap-2 mt-3">
                                <button
                                    type="button"
                                    class="btn btn-et-primary btn-sm"
                                    :disabled="savingEvent[`${method.code}:${selectedEvent[method.code]}`]"
                                    @click="saveEvent(method.code)"
                                >
                                    <span
                                        v-if="savingEvent[`${method.code}:${selectedEvent[method.code]}`]"
                                        class="spinner-border spinner-border-sm me-1"
                                    ></span>
                                    Guardar evento
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-et-ghost btn-sm"
                                    :disabled="testing[`${method.code}:${selectedEvent[method.code]}`]"
                                    @click="testMethod(method.code, selectedEvent[method.code], eventTestToken(method.code))"
                                >
                                    <span
                                        v-if="testing[`${method.code}:${selectedEvent[method.code]}`]"
                                        class="spinner-border spinner-border-sm me-1"
                                    ></span>
                                    Probar conexión
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.payment-method-head {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    width: 100%;
    padding: 0.85rem 1rem;
    border: 0;
    background: none;
    color: var(--et-text);
    font-size: 1rem;
    text-align: left;
}

.payment-method-head .bi-chevron-down {
    transition: transform var(--et-transition);
}

.payment-method-head .bi-chevron-down.is-open {
    transform: rotate(180deg);
}
</style>
